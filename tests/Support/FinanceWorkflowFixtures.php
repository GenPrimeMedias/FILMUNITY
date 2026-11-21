<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\AssociationTaxService as Tax;
use App\Support\AssociationFinance as Finance;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Existing shared finance fixtures; no application bootstrap or database reset is added. */
trait FinanceWorkflowFixtures
{
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
        return ['client_token' => (string) Str::uuid(), 'flow' => $flow, 'entry_date' => '2026-11-20', 'due_date' => '2026-11-25', 'party_account_id' => $party, 'counter_account_id' => DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', $flow === 'invoice' ? 'yearly' : 'expense')->value('id'), 'description' => 'Services', 'reference' => 'SOURCE-100', 'taxable_base' => '10000.00', 'exempt_base' => '1000.00', 'gst_rate' => '18.00', 'mode' => 'cgst', 'tds_base' => $flow === 'bill' ? '10000.00' : '0.00', 'tds_rate' => $flow === 'bill' ? '10.00' : '0.00', 'hsn_sac' => '999599', 'place_state' => '33', 'supplier_state' => '33', 'party_name' => 'Test party', 'party_address' => 'Chennai', 'party_gstin' => '33AAAAA0000A1Z5', 'party_pan' => 'AAAAA0000A', 'tds_reference' => $flow === 'bill' ? '393(1) Table reviewed' : '', 'tds_event_date' => '2026-11-20', 'claim_itc' => $flow === 'bill', 'review_note' => 'Rate, threshold and treatment reviewed.', 'confirmed' => true];
    }

    private function postTax(User $owner, string $id, string $v): void
    {
        Finance::decide($id, $v, $owner, 'submit', 'Reviewed tax snapshot');
        Finance::decide($id, $v, $owner, 'post', 'Approved tax entries');
    }

    private function charges(string $id, bool $bill = false): array
    {
        $counter = DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', $bill ? 'expense' : 'yearly')->value('id');
        $second = DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', $bill ? 'expense' : 'other')->value('id');

        return [['counter_account_id' => $counter, 'description' => 'First reviewed charge', 'amount' => '100.00', 'treatment' => 'taxable', 'gst_rate' => '18.00', 'hsn_sac' => '999599', 'exemption_reason' => null, 'tds_base' => $bill ? '100.00' : '0.00'], ['counter_account_id' => $second, 'description' => 'Second charge', 'amount' => '50.00', 'treatment' => 'taxable', 'gst_rate' => '5.00', 'hsn_sac' => '999599', 'exemption_reason' => null, 'tds_base' => '0.00'], ['counter_account_id' => $second, 'description' => 'Reviewed exempt charge', 'amount' => '25.00', 'treatment' => 'exempt', 'gst_rate' => '0.00', 'hsn_sac' => null, 'exemption_reason' => 'Reviewed exemption basis', 'tds_base' => '0.00']];
    }

    private function correction(string $original, string $kind, string $amount): array
    {
        return [
            'client_token' => (string) Str::uuid(),
            'original_document_id' => $original,
            'kind' => $kind,
            'entry_date' => '2026-11-20',
            'charges' => [['line_no' => 1, 'amount' => $amount]],
            'reason' => 'Reviewed change to the original charge',
        ];
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
            Finance::setup($id, $owner, '2026-11-01');
        }

        return [$owner, $id];
    }
}
