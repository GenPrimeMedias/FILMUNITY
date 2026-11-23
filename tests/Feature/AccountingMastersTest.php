<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\AccountingMoney;
use App\Support\AssociationFinance as Finance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\WorkspaceMutationRequests;
use Tests\TestCase;

class AccountingMastersTest extends TestCase
{
    use DatabaseTransactions;
    use WorkspaceMutationRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        config(['association-registration.reviewer_ids' => []]);
    }

    public function test_owner_scope_applies_to_master_reads_and_writes(): void
    {
        [$owner,$id] = $this->association(true);
        $this->actingAs(User::factory()->create())->get("/association-finance/$id/masters")->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$id/masters/vendors", ['name' => 'Vendor', 'reference' => 'V-1'])->assertNotFound();
        $this->actingAs($owner)->get("/association-finance/$id/masters")->assertOk()->assertInertia(fn (Page $p) => $p->component('accounting-masters')->has('groups', 13));
    }

    public function test_vendor_creation_is_atomic_and_reference_is_unique(): void
    {
        [$owner,$id] = $this->association(true);
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/masters/vendors", ['name' => 'Printer', 'reference' => 'PRINT-1', 'email' => 'printer@example.org'])->assertSessionHasNoErrors();
        $vendor = DB::table('association_finance_vendors')->where('association_id', $id)->first();
        $a = DB::table('association_finance_accounts')->where('vendor_id', $vendor->id)->first();
        $this->assertSame('liability', $a->type);
        $this->assertSame(AccountingMasters::group($id, 'creditors'), $a->group_id);
        $this->postWithWorkspaceLease("/association-finance/$id/masters/vendors", ['name' => 'Duplicate', 'reference' => 'PRINT-1'])->assertSessionHasErrors('finance');
        $this->assertSame(1, DB::table('association_finance_vendors')->where('association_id', $id)->count());
    }

    public function test_groups_validate_nature_and_tenant_before_creation(): void
    {
        [$owner,$id] = $this->association(true);
        [, $foreign] = $this->association(true);
        $this->actingAs($owner);
        $parent = AccountingMasters::group($foreign, 'assets');
        $this->postWithWorkspaceLease("/association-finance/$id/masters/groups", ['name' => 'Wrong tenant', 'nature' => 'ASSET', 'parent_id' => $parent])->assertSessionHasErrors('finance');
        $parent = AccountingMasters::group($id, 'assets');
        $this->postWithWorkspaceLease("/association-finance/$id/masters/groups", ['name' => 'Wrong nature', 'nature' => 'INCOME', 'parent_id' => $parent])->assertSessionHasErrors('finance');
        $this->postWithWorkspaceLease("/association-finance/$id/masters/groups", ['name' => 'Equipment', 'nature' => 'ASSET', 'parent_id' => $parent])->assertSessionHasNoErrors();
    }

    public function test_group_assignment_rejects_incompatible_classification(): void
    {
        [$owner,$id] = $this->association(true);
        $cash = DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', 'cash')->value('id');
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/masters/ledgers/$cash/group", ['group_id' => AccountingMasters::group($id, 'income')])->assertSessionHasErrors('finance');
        $this->postWithWorkspaceLease("/association-finance/$id/masters/ledgers/$cash/group", ['group_id' => AccountingMasters::group($id, 'bank')])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_finance_events', ['association_id' => $id, 'action' => 'ledger_group_updated']);
    }

    public function test_sequence_is_independent_per_kind_year_and_association_and_rolls_back(): void
    {
        [, $a] = $this->association(true);
        [, $b] = $this->association(true);
        $allocate = fn ($id, $date, $kind) => DB::transaction(function () use ($id, $date, $kind) {
            Finance::lock($id);

            return AccountingMasters::number($id, $date, $kind);
        });
        $this->assertSame('PAY-2025-26-000001', $allocate($a, '2026-03-31', 'payment'));
        $this->assertSame('PAY-2026-27-000001', $allocate($a, '2026-04-01', 'payment'));
        $this->assertSame('PAY-2026-27-000002', $allocate($a, '2026-04-01', 'payment'));
        $this->assertSame('RCPT-2026-27-000001', $allocate($a, '2026-04-01', 'receipt'));
        $this->assertSame('PAY-2026-27-000001', $allocate($b, '2026-04-01', 'payment'));
        try {
            DB::transaction(function () use ($a): void {
                Finance::lock($a);
                AccountingMasters::number($a, '2026-04-01', 'payment');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }$this->assertSame('PAY-2026-27-000003', $allocate($a, '2026-04-01', 'payment'));
    }

    public function test_decimal_arithmetic_preserves_cents_without_float_rounding(): void
    {
        $this->assertSame('0.30', AccountingMoney::add('0.10', '0.20'));
        $this->assertSame(999999999999999, AccountingMoney::minor('9999999999999.99'));
        $this->assertSame('9999999999999.99', AccountingMoney::fromMinor('999999999999999'));
        $this->expectException(\InvalidArgumentException::class);
        AccountingMoney::minor('1.001');
    }

    public function test_group_seed_and_member_sync_are_idempotent_and_do_not_post_money(): void
    {
        [$owner,$id] = $this->association(true);
        DB::transaction(function () use ($id): void {
            Finance::lock($id);
            AccountingMasters::seed($id);
            AccountingMasters::seed($id);
        });
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/masters/sync")->assertSessionHasNoErrors();
        $this->postWithWorkspaceLease("/association-finance/$id/masters/sync")->assertSessionHasNoErrors();
        $this->assertSame(13, DB::table('association_account_groups')->where('association_id', $id)->count());
        $this->assertSame(9, DB::table('association_finance_accounts')->where('association_id', $id)->count());
        $this->assertSame(0, DB::table('association_finance_vouchers')->where('association_id', $id)->count());
        $this->artisan('accounting:check', ['association' => $id])->assertExitCode(0);
    }

    public function test_search_sort_and_pagination_use_the_same_owner_scope(): void
    {
        [$owner,$id] = $this->association(true);
        [, $foreign] = $this->association(true);
        $this->actingAs($owner);
        for ($i = 0; $i < 17; $i++) {
            AccountingMasters::vendor($id, $owner, ['name' => 'Supplier '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'reference' => 'SUP-'.$i, 'email' => null]);
        }
        $this->get("/association-finance/$id/masters?view=vendors&sort=name&direction=desc")->assertInertia(fn (Page $p) => $p->where('records.total', 17)->has('records.data', 15)->where('records.data.0.name', 'Supplier 16'));
        $this->get("/association-finance/$id/masters?view=vendors&q=SUP-16")->assertInertia(fn (Page $p) => $p->where('records.total', 1)->where('records.data.0.vendor_reference', 'SUP-16'));
    }

    private function association(bool $finance = false): array
    {
        $owner = User::factory()->create(['association_signup' => true]);
        $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert(['id' => $id, 'applicant_id' => $owner->id, 'name' => 'Finance Test Association', 'name_key' => $id, 'organisation_type' => 'Association', 'industry' => 'Tamil', 'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => 'approved', 'official_contact_email' => 'office@example.org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_workspace_settings')->insert(['association_id' => $id, 'display_name' => 'Finance Test Association', 'accent' => '#b9d66b', 'fields' => '[]', 'version' => 0, 'yearly_fee_minor' => 10000, 'lifetime_fee_minor' => 100000, 'payment_instructions' => 'Bank transfer']);
        if ($finance) {
            Finance::setup($id,$owner,'2026-11-01');
        }

return [$owner, $id];
    }
}
