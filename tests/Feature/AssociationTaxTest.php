<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\AssociationTaxService as Tax;
use App\Services\Accounting\BillSettlementService as Bills;
use App\Services\Accounting\TaxConfigurationVersions;
use App\Support\AssociationFinance as Finance;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\WorkspaceMutationRequests;
use Tests\TestCase;

class AssociationTaxTest extends TestCase
{
    use DatabaseTransactions;
    use WorkspaceMutationRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
        config(['association-registration.reviewer_ids' => []]);
        Carbon::setTestNow('2026-11-20 12:00:00');
        CarbonImmutable::setTestNow('2026-11-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function profile(User $owner, string $id): void
    {
        Tax::settings($id, $owner, ['legal_name' => 'Test Association', 'address' => 'Chennai, Tamil Nadu', 'state_code' => '33', 'gst_registered' => true, 'gstin' => '33AAAAA0000A1Z5', 'pan' => 'AAAAA0000A', 'tan' => 'AAAA00000A', 'tds_enabled' => true, 'effective_from' => '2026-11-01', 'policy_note' => 'Reviewed general supply; no automatic RWA exemption.', 'confirmed' => true]);
    }

    private function data(string $id, string $party, string $flow = 'invoice'): array
    {
        return ['tax_configuration_id' => TaxConfigurationVersions::effective($id, '2026-11-20')?->configuration_version_id, 'client_token' => (string) Str::uuid(), 'flow' => $flow, 'entry_date' => '2026-11-20', 'due_date' => '2026-11-25', 'party_account_id' => $party, 'counter_account_id' => DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', $flow === 'invoice' ? 'yearly' : 'expense')->value('id'), 'description' => 'Services', 'reference' => 'SOURCE-100', 'taxable_base' => '10000.00', 'exempt_base' => '1000.00', 'gst_rate' => '18.00', 'mode' => 'cgst', 'tds_base' => $flow === 'bill' ? '10000.00' : '0.00', 'tds_rate' => $flow === 'bill' ? '10.00' : '0.00', 'hsn_sac' => '999599', 'place_state' => '33', 'supplier_state' => '33', 'party_name' => 'Test party', 'party_address' => 'Chennai', 'party_gstin' => '33AAAAA0000A1Z5', 'party_pan' => 'AAAAA0000A', 'tds_reference' => $flow === 'bill' ? '393(1) Table reviewed' : '', 'tds_event_date' => '2026-11-20', 'claim_itc' => $flow === 'bill', 'review_note' => 'Rate, threshold and treatment reviewed.', 'confirmed' => true];
    }

    private function postTax(User $owner, string $id, string $v): void
    {
        Finance::decide($id, $v, $owner, 'submit', 'Reviewed tax snapshot');
        Finance::decide($id, $v, $owner, 'post', 'Approved tax entries');
    }

    public function test_reads_and_settings_are_owner_scoped(): void
    {
        [$owner,$id] = $this->association(true);
        $this->actingAs(User::factory()->create())->get("/association-finance/$id/tax")->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$id/tax/settings", [])->assertNotFound();
        $this->actingAs($owner)->get("/association-finance/$id/tax")->assertOk()->assertInertia(fn (Page $p) => $p->component('association-tax'));
    }

    public function test_member_invoice_exact_totals_snapshot_number_and_idempotency(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $data = $this->data($id, $party);
        $v = Tax::save($id, $owner, $data);
        $this->assertSame($v, Tax::save($id, $owner, $data));
        $this->postTax($owner, $id, $v);
        $this->assertSame('12800.00', Bills::remaining($v));
        $t = DB::table('association_finance_tax_documents')->where('voucher_id', $v)->first();
        $this->assertSame('900.00', $t->cgst);
        $this->assertLessThanOrEqual(16, strlen($t->serial));
        $this->actingAs($owner)->get("/association-finance/$id/tax/documents/$v")->assertOk()->assertSee($t->serial);
        $this->actingAs(User::factory()->create())->get("/association-finance/$id/tax/documents/$v")->assertNotFound();
    }

    public function test_vendor_bill_splits_input_tax_tds_and_net_party_payable(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Vendor', 'reference' => 'V1', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $v = Tax::save($id, $owner, $this->data($id, $party, 'bill'));
        $this->postTax($owner, $id, $v);
        $this->assertSame('11800.00', Bills::remaining($v));
        $this->assertDatabaseHas('association_finance_lines', ['voucher_id' => $v, 'account_id' => $party, 'credit_minor' => 1180000]);
        $this->assertDatabaseHas('association_finance_tax_documents', ['voucher_id' => $v, 'tds' => '1000.00']);
        $this->assertSame(2, DB::table('association_finance_lines as l')->join('association_finance_accounts as a', 'a.id', '=', 'l.account_id')->where('l.voucher_id', $v)->where('a.code', 'like', 'TAX-IN-%')->count());
    }

    public function test_noneligible_input_gst_is_included_in_expense_cost(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Vendor', 'reference' => 'V1', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $data = $this->data($id, $party, 'bill');
        $data['claim_itc'] = false;
        $v = Tax::save($id, $owner, $data);
        $this->postTax($owner, $id, $v);
        $this->assertDatabaseHas('association_finance_lines', ['voucher_id' => $v, 'account_id' => $data['counter_account_id'], 'debit_minor' => 1280000]);
    }

    public function test_tax_invoice_cannot_be_edited_by_ordinary_billing_editor(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $d = $this->data($id, $party);
        $v = Tax::save($id, $owner, $d);
        $this->expectException(ValidationException::class);
        Bills::save($id, $owner, [...$d, 'amount' => '1.00'], $v);
    }

    public function test_current_tds_rejects_legacy_reference_and_earlier_unreconciled_event(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Vendor', 'reference' => 'V1', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $data = $this->data($id, $party, 'bill');
        foreach ([['tds_reference' => '194J'], ['tds_event_date' => '2026-11-19']] as $change) {
            try {
                Tax::save($id, $owner, [...$data, ...$change]);
                $this->fail('Unsafe TDS input was accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('finance', $e->errors());
            }
        }$this->assertSame(0, DB::table('association_finance_tax_documents')->where('association_id', $id)->count());
    }

    public function test_component_rounding_and_exempt_amounts_are_exact(): void
    {
        $d = ['taxable_base' => '0.10', 'exempt_base' => '0.20', 'gst_rate' => '18.00', 'mode' => 'cgst', 'tds_base' => '0.00', 'tds_rate' => '0.00'];
        $t = Tax::calculate($d);
        $this->assertSame('0.01', $t['cgst']);
        $this->assertSame('0.32', $t['net']);
        $d['taxable_base'] = '7500.00';
        $d['exempt_base'] = '0.00';
        $this->assertSame('675.00', Tax::calculate($d)['cgst']);
    }

    private function member(User $owner, string $id): array
    {
        $u = User::factory()->create();
        $app = (string) Str::uuid();
        $payment = (string) Str::uuid();
        $membership = (string) Str::uuid();
        $receipt = (string) Str::uuid();
        DB::table('association_membership_applications')->insert(['id' => $app, 'association_id' => $id, 'member_id' => $u->id, 'statement' => 'Experience', 'status' => 'accepted_for_payment', 'form_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_payment_requests')->insert(['id' => $payment, 'application_id' => $app, 'association_id' => $id, 'member_id' => $u->id, 'created_by' => $owner->id, 'membership_type' => 'yearly', 'amount_minor' => 10000, 'currency' => 'INR', 'instructions' => 'Bank transfer', 'status' => 'acknowledged', 'snapshot' => '{}', 'acknowledged_at' => '2026-11-10 10:00:00+00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_memberships')->insert(['id' => $membership, 'payment_id' => $payment, 'association_id' => $id, 'member_id' => $u->id, 'number' => 'MEM-'.Str::random(12), 'membership_type' => 'yearly', 'status' => 'active', 'starts_at' => '2026-11-10 10:00:00+00', 'ends_at' => '2027-11-10 10:00:00+00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_payment_receipts')->insert(['id' => $receipt, 'payment_id' => $payment, 'membership_id' => $membership, 'association_id' => $id, 'number' => 'REC-'.Str::random(12), 'snapshot' => '{}', 'issued_at' => '2026-11-10 10:00:00+00']);
        DB::transaction(function () use ($id, $membership): void {
            Finance::lock($id);
            AccountingMasters::syncMembers($id, $membership);
        });

        return [DB::table('association_finance_accounts')->where('membership_id', $membership)->value('id'), $receipt];
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
