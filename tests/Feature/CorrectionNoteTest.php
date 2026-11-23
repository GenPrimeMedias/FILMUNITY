<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\BillSettlementService as Bills;
use App\Services\Accounting\CorrectionNoteService as Notes;
use App\Support\AssociationFinance as Finance;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\WorkspaceMutationRequests;
use Tests\TestCase;

class CorrectionNoteTest extends TestCase
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

    public function test_only_owner_can_create_corrections(): void
    {
        [$owner,$id] = $this->association(true);
        $this->actingAs(User::factory()->create())->postWithWorkspaceLease("/association-finance/$id/billing/notes", [])->assertNotFound();
    }

    public function test_member_credit_note_posts_exactly_once_and_settles_invoice(): void
    {
        [$owner,$id] = $this->association(true);
        [$party] = $this->member($owner, $id);
        $invoice = $this->entry($owner, $id, $party, 'invoice', '100.00');
        $data = $this->noteData($invoice, 'credit_note', '30.00');
        $note = Notes::save($id, $owner, $data);
        $this->assertSame($note, Notes::save($id, $owner, $data));
        $this->postNote($owner, $id, $note);
        $this->assertSame($note, Notes::save($id, $owner, $data));
        $this->applyNote($owner, $id, $note, $invoice, '30.00');
        $this->assertSame('70.00', Bills::remaining($invoice));
        $this->assertSame('70.00', Notes::available($id, $invoice));
        $this->assertStringStartsWith('CN-2026-27-', DB::table('association_finance_vouchers')->where('id', $note)->value('number'));
        $this->assertDatabaseHas('association_finance_lines', ['voucher_id' => $note, 'account_id' => $party, 'credit_minor' => 3000]);
        $this->assertSame(0, DB::table('association_finance_lines as l')->join('association_finance_accounts as a', 'a.id', '=', 'l.account_id')->where('l.voucher_id', $note)->where('a.is_money', true)->count());
    }

    public function test_vendor_debit_note_reduces_bill_without_cash_payment(): void
    {
        [$owner,$id] = $this->association(true);
        AccountingMasters::vendor($id, $owner, ['name' => 'Printer', 'reference' => 'PRINT', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $bill = $this->entry($owner, $id, $party, 'bill', '100.00');
        $note = Notes::save($id, $owner, $this->noteData($bill, 'debit_note', '20.00'));
        $this->postNote($owner, $id, $note);
        $this->applyNote($owner, $id, $note, $bill, '20.00');
        $this->assertSame('80.00', Bills::remaining($bill));
        $this->assertDatabaseHas('association_finance_lines', ['voucher_id' => $note, 'account_id' => $party, 'debit_minor' => 2000]);
        $this->assertStringStartsWith('DN-2026-27-', DB::table('association_finance_vouchers')->where('id', $note)->value('number'));
    }

    public function test_open_period_note_can_adjust_closed_period_original_without_rewriting_it(): void
    {
        [$owner,$id] = $this->association(true);
        [$party] = $this->member($owner, $id);
        $invoice = $this->entry($owner, $id, $party, 'invoice', '100.00');
        $before = (array) DB::table('association_finance_vouchers')->where('id', $invoice)->first();
        DB::table('association_finance_settings')->where('association_id', $id)->update(['locked_through' => '2026-11-15']);
        $note = Notes::save($id, $owner, $this->noteData($invoice, 'credit_note', '25.00'));
        $this->postNote($owner, $id, $note);
        $this->applyNote($owner, $id, $note, $invoice, '25.00');
        $this->assertSame('75.00', Bills::remaining($invoice));
        $this->assertEquals($before, (array) DB::table('association_finance_vouchers')->where('id', $invoice)->first());
    }

    public function test_posting_rechecks_ceiling_after_another_draft_was_posted(): void
    {
        [$owner,$id] = $this->association(true);
        [$party] = $this->member($owner, $id);
        $invoice = $this->entry($owner, $id, $party, 'invoice', '100.00');
        $a = Notes::save($id, $owner, $this->noteData($invoice, 'credit_note', '60.00'));
        $b = Notes::save($id, $owner, $this->noteData($invoice, 'credit_note', '60.00'));
        $this->postNote($owner, $id, $a);
        Finance::decide($id, $b, $owner, 'submit', 'Review second note');
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/vouchers/$b/decision", ['action' => 'post', 'note' => 'Confirm', 'confirmed' => true])->assertSessionHasErrors('finance');
        $this->assertDatabaseHas('association_finance_vouchers', ['id' => $b, 'status' => 'submitted']);
        $this->assertSame('40.00', Notes::available($id, $invoice));
    }

    public function test_draft_edit_keeps_origin_and_number_and_evidence_is_private(): void
    {
        [$owner,$id] = $this->association(true);
        [$party] = $this->member($owner, $id);
        $invoice = $this->entry($owner, $id, $party, 'invoice', '100.00');
        $data = $this->noteData($invoice, 'credit_note', '20.00');
        $data['evidence'] = UploadedFile::fake()->create('approval.pdf', 20, 'application/pdf');
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/billing/notes", $data)->assertSessionHasNoErrors();
        $note = DB::table('association_finance_notes')->where('original_document_id', $invoice)->value('voucher_id');
        $number = DB::table('association_finance_vouchers')->where('id', $note)->value('number');
        $this->get("/association-finance/$id/vouchers/$note/evidence")->assertDownload();
        unset($data['evidence']);
        $data['amount'] = '25.00';
        $data['reason'] = 'Correct draft amount';
        $this->postWithWorkspaceLease("/association-finance/$id/billing/notes/$note", $data)->assertSessionHasNoErrors();
        $this->assertSame($number, DB::table('association_finance_vouchers')->where('id', $note)->value('number'));
        $this->assertSame('25.00', Bills::remaining($note));
        $other = $this->entry($owner, $id, $party, 'invoice', '100.00');
        $data['original_document_id'] = $other;
        $this->postWithWorkspaceLease("/association-finance/$id/billing/notes/$note", $data)->assertSessionHasErrors('finance');
        $this->assertDatabaseHas('association_finance_notes', ['voucher_id' => $note, 'original_document_id' => $invoice]);
        $this->actingAs(User::factory()->create())->get("/association-finance/$id/vouchers/$note/evidence")->assertNotFound();
    }

    public function test_note_reversal_requires_release_and_original_waits_for_note_reversal(): void
    {
        [$owner,$id] = $this->association(true);
        [$party] = $this->member($owner, $id);
        $invoice = $this->entry($owner, $id, $party, 'invoice', '100.00');
        $note = Notes::save($id, $owner, $this->noteData($invoice, 'credit_note', '30.00'));
        $this->postNote($owner, $id, $note);
        $decision = ['action' => 'reverse', 'entry_date' => '2026-11-20', 'note' => 'Correction', 'confirmed' => true];
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/vouchers/$invoice/decision", $decision)->assertSessionHasErrors('finance');
        $this->applyNote($owner, $id, $note, $invoice, '30.00');
        $this->postWithWorkspaceLease("/association-finance/$id/vouchers/$note/decision", $decision)->assertSessionHasErrors('finance');
        $x = DB::table('association_bill_allocations')->where('settlement_id', $note)->value('id');
        Bills::release($id, $owner, $x, 'Reverse correction');
        Finance::decide($id, $note, $owner, 'reverse', 'Cancel note', '2026-11-20');
        $this->assertSame('100.00', Notes::available($id, $invoice));
        Finance::decide($id, $invoice, $owner, 'reverse', 'Cancel original', '2026-11-20');
        $this->assertDatabaseHas('association_finance_vouchers', ['id' => $invoice, 'status' => 'reversed']);
    }

    private function noteData(string $original, string $kind, string $amount): array
    {
        return ['client_token' => (string) Str::uuid(), 'kind' => $kind, 'original_document_id' => $original, 'entry_date' => '2026-11-20', 'amount' => $amount, 'reason' => 'Approved correction', 'reference' => null];
    }

    private function postNote(User $owner, string $id, string $note): void
    {
        Finance::decide($id, $note, $owner, 'submit', 'Reviewed correction');
        Finance::decide($id, $note, $owner, 'post', 'Confirmed correction');
    }

    private function applyNote(User $owner, string $id, string $note, string $original, string $amount): void
    {
        $data = $this->allocation($note, $original, $amount);
        $data['allocation_date'] = '2026-11-20';
        Bills::allocate($id, $owner, $data);
    }

    private function account(string $id, string $key): string
    {
        return DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', $key)->value('id');
    }

    private function data(string $id, string $party, string $flow, string $amount): array
    {
        return ['client_token' => (string) Str::uuid(), 'flow' => $flow, 'party_account_id' => $party, 'counter_account_id' => $this->account($id, match ($flow) {
            'invoice' => 'yearly','bill' => 'expense',default => 'bank'
        }), 'amount' => $amount, 'entry_date' => '2026-11-10', 'due_date' => in_array($flow, ['invoice', 'bill'], true) ? '2026-11-15' : null, 'description' => 'Billing test', 'reference' => 'TEST'];
    }

    private function entry(User $owner, string $id, string $party, string $flow, string $amount, bool $post = true): string
    {
        $v = Bills::save($id, $owner, $this->data($id, $party, $flow, $amount));
        if ($post) {
            Finance::decide($id, $v, $owner, 'submit', 'Reviewed');
            Finance::decide($id, $v, $owner, 'post', 'Reviewed');
        }

return $v;
    }

    private function allocation(string $source, string $target, string $amount): array
    {
        return ['client_token' => (string) Str::uuid(), 'settlement_id' => $source, 'document_id' => $target, 'amount' => $amount, 'allocation_date' => '2026-11-15', 'note' => 'Settlement'];
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

        return [DB::table('association_finance_accounts')->where('membership_id',$membership)->value('id'), $receipt];
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
