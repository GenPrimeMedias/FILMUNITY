<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class MembershipRenewalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        config(['association-registration.reviewer_ids' => []]);
        $this->clock('2028-02-29T10:00:00+00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_early_renewal_extends_expiry_and_preserves_number_receipts_and_initial_payment(): void
    {
        [$owner, $member, $association, $membership] = $this->fixture();
        $oldReceipt = DB::table('association_payment_receipts')->where('membership_id', $membership->id)->first();
        $this->clock('2029-02-10T10:00:00+00:00');
        DB::select("select set_config('TimeZone', 'Asia/Kolkata', true)");
        $url = "/membership-renewals/$membership->id";
        $data = $this->requestData($association, $membership);
        $this->actingAs($member)->get($url)->assertInertia(fn (Page $page) => $page
            ->component('membership-renewal')->where('membership.canRenew', true)->where('membership.display_status', 'expiring_soon'));
        $this->post($url, $data)->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('renewal_membership_id', $membership->id)->first();
        $this->post($url, $data)->assertRedirect('/membership-payments/'.$payment->id);
        $this->assertSame(1, DB::table('association_payment_requests')->where('renewal_membership_id', $membership->id)->count());
        $this->actingAs($owner)->post("/association-payments/$payment->id/zero-fee")->assertSessionHasNoErrors();
        $this->post("/association-payments/$payment->id/zero-fee")->assertSessionHasNoErrors();
        $updated = DB::table('association_memberships')->where('id', $membership->id)->first();
        $this->assertSame($membership->number, $updated->number);
        $this->assertSame($membership->payment_id, $updated->payment_id);
        $this->assertSame('2030-02-28 10:00:00', CarbonImmutable::parse($updated->ends_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('association_memberships')->where('id', $membership->id)->count());
        $this->assertSame(2, DB::table('association_payment_receipts')->where('membership_id', $membership->id)->count());
        $this->assertSame(2, DB::table('association_membership_terms')->where('membership_id', $membership->id)->count());
        $this->assertSame($oldReceipt->snapshot, DB::table('association_payment_receipts')->where('id', $oldReceipt->id)->value('snapshot'));
        $term = DB::table('association_membership_terms')->where('payment_id', $payment->id)->first();
        $this->assertTrue(CarbonImmutable::parse($membership->ends_at)->equalTo(CarbonImmutable::parse($term->starts_at)));
        $this->actingAs($member)->post($url, $data)->assertSessionHasErrors('payment');
        $this->get('/membership-applications')->assertInertia(fn (Page $page) => $page
            ->where('applications.data.0.payment.id', $membership->payment_id)
            ->where('applications.data.0.membership.display_status', 'active'));
    }

    public function test_expired_renewal_restarts_at_acknowledgement_and_restores_workspace_access(): void
    {
        [$owner, $member, $association, $membership] = $this->fixture();
        $this->clock('2029-05-20T12:00:00+00:00');
        $this->actingAs($member)->get("/association-member-workspaces/$membership->id")->assertNotFound();
        $this->post("/membership-renewals/$membership->id", $this->requestData($association, $membership))->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('renewal_membership_id', $membership->id)->value('id');
        $this->clock('2029-05-25T12:00:00+00:00');
        $this->actingAs($owner)->post("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
        $term = DB::table('association_membership_terms')->where('payment_id', $payment)->first();
        $this->assertSame('2029-05-25 12:00:00', CarbonImmutable::parse($term->starts_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2030-05-25 12:00:00', CarbonImmutable::parse($term->ends_at)->utc()->format('Y-m-d H:i:s'));
        $this->actingAs($member)->get("/association-member-workspaces/$membership->id")->assertOk();
    }

    public function test_fee_is_confirmed_at_request_and_paid_renewal_uses_existing_proof_review(): void
    {
        [$owner, $member, $association, $membership] = $this->fixture();
        $this->clock('2029-02-10T10:00:00+00:00');
        $stale = $this->requestData($association, $membership);
        DB::table('association_workspace_settings')->where('association_id', $association)->update(['version' => 1, 'yearly_fee_minor' => 15025]);
        $this->actingAs($member)->post("/membership-renewals/$membership->id", $stale)->assertSessionHasErrors('payment');
        $this->post("/membership-renewals/$membership->id", $this->requestData($association, $membership))->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('renewal_membership_id', $membership->id)->first();
        $this->assertSame(15025, (int) $payment->amount_minor);
        DB::table('association_workspace_settings')->where('association_id', $association)->update(['version' => 2, 'yearly_fee_minor' => 25000]);
        $this->assertSame(15025, (int) DB::table('association_payment_requests')->where('id', $payment->id)->value('amount_minor'));
        $this->post("/membership-payments/$payment->id/submissions", [
            'reference' => 'RENEWAL-BANK-001', 'paid_on' => '2029-02-10',
            'proof' => UploadedFile::fake()->create('renewal.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $submission = DB::table('association_payment_submissions')->where('payment_id', $payment->id)->value('id');
        $this->actingAs($owner)->patch("/association-payments/$payment->id/submissions/$submission", ['decision' => 'accept', 'note' => 'Verified renewal transfer'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_payment_events', ['payment_id' => $payment->id, 'action' => 'membership_renewed']);
        $receipt = DB::table('association_payment_receipts')->where('payment_id', $payment->id)->first();
        $snapshot = json_decode($receipt->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('150.25', $snapshot['amount']);
        $this->assertSame('renewal', $snapshot['purpose']);
        $this->actingAs($member)->get("/membership-receipts/$receipt->id")->assertOk();
    }

    public function test_renewals_are_member_scoped_and_block_lifetime_suspension_and_early_requests(): void
    {
        [$owner, $member, $association, $membership] = $this->fixture();
        $stranger = User::factory()->create();
        $otherOwner = User::factory()->create();
        $url = "/membership-renewals/$membership->id";
        $data = $this->requestData($association, $membership);
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->post($url, $data)->assertNotFound();
        $this->actingAs($otherOwner)->get($url)->assertNotFound();
        $this->actingAs($owner)->get($url)->assertOk();
        $this->post($url, $data)->assertForbidden();
        $this->actingAs($member)->post($url, $data)->assertSessionHasErrors('payment');
        $this->clock('2029-02-10T10:00:00+00:00');
        $this->post($url, [...$data, 'confirmed' => false])->assertSessionHasErrors('confirmed');
        DB::table('association_memberships')->where('id', $membership->id)->update(['status' => 'suspended']);
        $this->post($url, $data)->assertSessionHasErrors('payment');
        $this->get("/association-member-workspaces/$membership->id")->assertNotFound();
        DB::table('association_memberships')->where('id', $membership->id)->update(['status' => 'active', 'membership_type' => 'lifetime', 'ends_at' => null]);
        $this->post($url, $data)->assertSessionHasErrors('payment');
        $this->get($url)->assertInertia(fn (Page $page) => $page->where('membership.display_status', 'lifetime')->where('membership.canRenew', false));
        $this->assertSame(0, DB::table('association_payment_requests')->whereNotNull('renewal_membership_id')->count());
    }

    public function test_unapproved_association_and_post_request_suspension_cannot_extend_membership(): void
    {
        [$owner, $member, $association, $membership] = $this->fixture();
        $this->clock('2029-02-10T10:00:00+00:00');
        $url = "/membership-renewals/$membership->id";
        $data = $this->requestData($association, $membership);
        DB::table('association_registration_applications')->where('id', $association)->update(['status' => 'declined']);
        $this->actingAs($member)->post($url, $data)->assertNotFound();
        DB::table('association_registration_applications')->where('id', $association)->update(['status' => 'approved']);
        $this->post($url, $data)->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('renewal_membership_id', $membership->id)->value('id');
        DB::table('association_memberships')->where('id', $membership->id)->update(['status' => 'suspended']);
        $this->actingAs($owner)->post("/association-payments/$payment/zero-fee")->assertSessionHasErrors('payment');
        $this->assertDatabaseMissing('association_payment_receipts', ['payment_id' => $payment]);
        $this->assertTrue(CarbonImmutable::parse($membership->ends_at)->equalTo(CarbonImmutable::parse(DB::table('association_memberships')->where('id', $membership->id)->value('ends_at'))));
    }

    public function test_member_filters_and_search_do_not_expose_other_accounts(): void
    {
        [$owner, $member, $association, $membership] = $this->fixture();
        $this->clock('2029-02-10T10:00:00+00:00');
        $this->actingAs($member)->get('/membership-renewals?status=expiring_soon&sort=ends_at&direction=asc')
            ->assertInertia(fn (Page $page) => $page->has('memberships.data', 1)->where('memberships.data.0.id', $membership->id));
        $this->get('/membership-renewals?status=expired')->assertInertia(fn (Page $page) => $page->has('memberships.data', 0));
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get('/membership-renewals?q='.$membership->number)->assertInertia(fn (Page $page) => $page->has('memberships.data', 0));
        $this->actingAs($owner)->get('/association-members?status=expiring_soon')->assertInertia(fn (Page $page) => $page->has('memberships.data', 1));
        $this->get("/membership-renewals/$membership->id?q=does-not-exist&sort=ends_at")->assertInertia(fn (Page $page) => $page->has('history.data', 0));
    }

    private function clock(string $instant): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($instant));
    }

    private function requestData(string $association, object $membership): array
    {
        $settings = DB::table('association_workspace_settings')->where('association_id', $association)->first();
        return ['version' => $settings->version, 'fee' => \App\Support\AssociationMembershipForm::formatFee($settings->yearly_fee_minor),
            'end_token' => CarbonImmutable::parse($membership->ends_at)->utc()->toIso8601String(), 'confirmed' => true];
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['association_signup' => true]);
        $member = User::factory()->create();
        $association = (string) Str::uuid();
        DB::table('association_registration_applications')->insert([
            'id' => $association, 'applicant_id' => $owner->id, 'name' => 'Renewal Test Association', 'name_key' => $association,
            'organisation_type' => 'Association', 'industry' => 'Tamil', 'city' => 'Chennai', 'representative_role' => 'Secretary',
            'status' => 'approved', 'official_contact_email' => 'office@example.org', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('association_workspace_settings')->insert([
            'association_id' => $association, 'display_name' => 'Renewal Test Association', 'accent' => '#b9d66b',
            'fields' => '[]', 'version' => 0, 'yearly_fee_minor' => 0, 'lifetime_fee_minor' => 50050,
            'payment_instructions' => 'Transfer to association account.',
        ]);
        $application = (string) Str::uuid();
        DB::table('association_membership_applications')->insert([
            'id' => $application, 'association_id' => $association, 'member_id' => $member->id,
            'membership_type' => 'yearly', 'fee_minor' => 0, 'currency' => 'INR', 'statement' => '',
            'status' => 'accepted_for_payment', 'form_snapshot' => json_encode(['name' => $member->name, 'email' => $member->email, 'answers' => []]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($owner)->post("/membership-payments/request/$application", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        $this->post("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
        $membership = DB::table('association_memberships')->where('payment_id', $payment)->first();
        return [$owner, $member, $association, $membership];
    }
}
