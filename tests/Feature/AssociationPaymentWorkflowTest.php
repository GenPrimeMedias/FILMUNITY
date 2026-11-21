<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PaymentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AssociationPaymentWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['association-registration.reviewer_ids' => []]);
    }

    public function test_request_proof_return_acknowledgement_and_member_workspace_are_consistent(): void
    {
        Storage::fake('local');
        Mail::fake();
        $owner = User::factory()->create(['association_signup' => true]);
        $member = User::factory()->create(['name' => 'Original Member']);
        $association = $this->association($owner);
        $application = $this->application($association, $member);

        $requestUrl = "/membership-payments/request/$application";
        $this->actingAs($owner)->post($requestUrl, ['instructions' => 'Pay Test Association via bank transfer.'])
            ->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        $detail = "/membership-payments/$payment";
        $this->post($requestUrl, ['instructions' => 'Second click'])->assertRedirect($detail);
        $this->assertDatabaseCount('association_payment_requests', 1);
        $this->assertDatabaseHas('association_payment_requests', ['id' => $payment, 'amount_minor' => 10025, 'status' => 'requested']);

        $this->actingAs($member)->post("$detail/submissions", $this->proof('BANK-001'))->assertRedirect($detail);
        $first = DB::table('association_payment_submissions')->where('payment_id', $payment)->value('id');
        $this->post("$detail/submissions", $this->proof('BANK-001'))->assertSessionHasErrors('payment');
        $this->actingAs($owner)->patch("/association-payments/$payment/submissions/$first", ['decision' => 'return'])
            ->assertSessionHasErrors('note');
        $this->patch("/association-payments/$payment/submissions/$first", ['decision' => 'return', 'note' => 'Please attach the full bank confirmation.'])
            ->assertRedirect($detail);
        $this->assertDatabaseHas('association_payment_submissions', ['id' => $first, 'status' => 'returned']);
        $this->actingAs($member)->post("$detail/submissions", $this->proof('BANK-002'))->assertRedirect($detail);
        $second = DB::table('association_payment_submissions')->where('payment_id', $payment)->where('status', 'submitted')->value('id');
        $this->actingAs($owner)->patch("/association-payments/$payment/submissions/$second", ['decision' => 'accept', 'note' => 'Bank receipt verified.'])
            ->assertRedirect($detail);
        $this->patch("/association-payments/$payment/submissions/$second", ['decision' => 'accept'])->assertRedirect($detail);
        $this->assertDatabaseCount('association_payment_receipts', 1);
        $this->assertDatabaseCount('association_memberships', 1);
        $this->assertDatabaseHas('association_payment_requests', ['id' => $payment, 'status' => 'acknowledged']);

        $membership = DB::table('association_memberships')->where('payment_id', $payment)->first();
        $receipt = DB::table('association_payment_receipts')->where('payment_id', $payment)->first();
        $this->assertNotNull($membership->ends_at);
        $this->actingAs($member)->get("/association-member-workspaces/$membership->id")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('association-member-workspace')
                ->where('membership.number', $membership->number));
        $this->get("/membership-receipts/$receipt->id")->assertOk()->assertSee('Original Member');
        $this->get("/membership-receipts/$receipt->id?download=1")->assertHeader('content-disposition', 'attachment; filename="'.$receipt->number.'.html"');
        $this->get("/association-workspaces/$association/setup")->assertNotFound();
        $this->get('/association-payments')->assertForbidden();
        $this->get('/association-members')->assertForbidden();
        $this->get("$detail/submissions/$first/proof")->assertDownload();

        DB::table('users')->where('id', $member->id)->update(['name' => 'Changed Name']);
        $this->get("/membership-receipts/$receipt->id")->assertSee('Original Member');
        DB::table('association_memberships')->where('id', $membership->id)->update(['ends_at' => now()->subSecond()]);
        $this->get("/association-member-workspaces/$membership->id")->assertNotFound();
        $this->get('/my-association-workspaces')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('memberships.data.0.display_status', 'expired')->where('memberships.data.0.canEnter', false));
        $this->get("/membership-receipts/$receipt->id")->assertOk();
    }

    public function test_unrelated_associations_and_members_cannot_access_or_decide_payments(): void
    {
        Storage::fake('local');
        Mail::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $otherOwner = User::factory()->create();
        $reviewer = User::factory()->create();
        $association = $this->association($owner);
        $this->association($otherOwner);
        $application = $this->application($association, $member);
        $this->actingAs($member)->post("/membership-payments/request/$application", ['instructions' => 'Bank'])->assertForbidden();
        $this->actingAs($owner)->post("/membership-payments/request/$application", ['instructions' => 'Bank'])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        $detail = "/membership-payments/$payment";
        $this->actingAs($member)->post("$detail/submissions", $this->proof('BANK-SCOPE'))->assertRedirect($detail);
        $submission = DB::table('association_payment_submissions')->where('payment_id', $payment)->value('id');

        $this->patch("/association-payments/$payment/submissions/$submission", ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($otherOwner)->get($detail)->assertNotFound();
        $this->get("$detail/submissions/$submission/proof")->assertNotFound();
        $this->patch("/association-payments/$payment/submissions/$submission", ['decision' => 'accept'])->assertNotFound();
        $this->get('/association-payments?q='.$payment)->assertInertia(
            fn (AssertableInertia $page) => $page->has('payments.data', 0));
        $this->actingAs($stranger)->get($detail)->assertNotFound();
        $otherApplication = $this->application($association, $stranger);
        $this->actingAs($owner)->post("/membership-payments/request/$otherApplication", ['instructions' => 'Bank']);
        $otherPayment = DB::table('association_payment_requests')->where('application_id', $otherApplication)->value('id');
        $this->actingAs($stranger)->post("/membership-payments/$otherPayment/submissions", $this->proof('bank-scope'))
            ->assertSessionHasErrors('reference');
        $this->get('/filmunity-admin/payments')->assertForbidden();

        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);
        $this->actingAs($reviewer)->get($detail)->assertOk();
        $this->get("$detail/submissions/$submission/proof")->assertDownload();
        $this->patch("/association-payments/$payment/submissions/$submission", ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($owner)->patch("/association-payments/$payment/submissions/$submission", ['decision' => 'accept']);
        $receipt = DB::table('association_payment_receipts')->where('payment_id', $payment)->value('id');
        $this->actingAs($stranger)->get("/membership-receipts/$receipt")->assertNotFound();
        $this->actingAs($reviewer)->get("/membership-receipts/$receipt")->assertOk();
        config(['association-registration.reviewer_ids' => []]);
        $this->get("/membership-receipts/$receipt")->assertNotFound();
    }

    public function test_zero_fee_lifetime_membership_activates_without_proof(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $association = $this->association($owner);
        $application = $this->application($association, $member, 'lifetime', 0);
        $this->actingAs($owner)->post("/membership-payments/request/$application", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        $this->actingAs($member)->post("/association-payments/$payment/zero-fee")->assertForbidden();
        $this->actingAs($owner)->post("/association-payments/$payment/zero-fee")->assertRedirect("/membership-payments/$payment");
        $this->post("/association-payments/$payment/zero-fee")->assertRedirect("/membership-payments/$payment");
        $membership = DB::table('association_memberships')->where('payment_id', $payment)->first();
        $this->assertNull($membership->ends_at);
        $this->assertDatabaseCount('association_payment_submissions', 0);
        $this->assertDatabaseCount('association_payment_receipts', 1);
        $this->actingAs($member)->get("/association-member-workspaces/$membership->id")->assertOk();
        $pending = DB::table('association_payment_notifications')->where('payment_id', $payment)->where('event_key', 'requested')->first();
        $sentBefore = Mail::sent(\App\Mail\AssociationPaymentUpdate::class)->count();
        PaymentNotification::deliver($pending->id);
        $sentAfterFirst = Mail::sent(\App\Mail\AssociationPaymentUpdate::class)->count();
        PaymentNotification::deliver($pending->id);
        $sentAfterSecond = Mail::sent(\App\Mail\AssociationPaymentUpdate::class)->count();
        $this->assertContains($sentAfterFirst - $sentBefore, [0, 1]);
        $this->assertSame($sentAfterFirst, $sentAfterSecond);
        $this->assertDatabaseHas('association_payment_notifications', ['id' => $pending->id, 'last_error' => null]);
    }

    public function test_legacy_applicant_confirms_current_plan_and_yearly_term_handles_leap_day(): void
    {
        Mail::fake();
        Storage::fake('local');
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $association = $this->association($owner);
        $application = $this->application($association, $member, null, null);
        DB::table('association_membership_applications')->where('id', $application)->update(['currency' => null]);
        $this->actingAs($owner)->post("/membership-payments/request/$application", ['instructions' => 'Bank'])
            ->assertSessionHasErrors('payment');
        $this->actingAs($member)->post("/membership-applications/$application/confirm-plan", ['membership_type' => 'yearly', 'version' => 99])
            ->assertSessionHasErrors('payment');
        $this->post("/membership-applications/$application/confirm-plan", ['membership_type' => 'yearly', 'version' => 0])
            ->assertRedirect('/membership-applications');
        $this->post("/membership-applications/$application/confirm-plan", ['membership_type' => 'lifetime', 'version' => 0])
            ->assertSessionHasErrors('payment');
        $this->assertDatabaseHas('association_membership_applications', ['id' => $application, 'membership_type' => 'yearly', 'fee_minor' => 10025]);
        $this->actingAs($owner)->post("/membership-payments/request/$application", ['instructions' => 'Bank']);
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        DB::select("select set_config('TimeZone', 'Asia/Kolkata', true)");
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2028-02-29 10:00:00', 'UTC'));
        try {
            $proof = $this->proof('BANK-LEAP');
            $proof['paid_on'] = '2028-02-29';
            $this->actingAs($member)->post("/membership-payments/$payment/submissions", $proof)->assertSessionHasNoErrors();
            $submission = DB::table('association_payment_submissions')->where('payment_id', $payment)->value('id');
            $this->actingAs($owner)->patch("/association-payments/$payment/submissions/$submission", ['decision' => 'accept'])
                ->assertSessionHasNoErrors();
            $term = DB::table('association_memberships')->where('payment_id', $payment)->value('ends_at');
            $this->assertSame('2029-02-28 10:00:00', CarbonImmutable::parse($term)->utc()->format('Y-m-d H:i:s'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_mail_failure_keeps_activation_and_receipt_and_can_be_retried(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $association = $this->association($owner);
        $application = $this->application($association, $member, 'lifetime', 0);
        $this->actingAs($owner)->post("/membership-payments/request/$application", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        $failingMailer = \Mockery::mock(\Illuminate\Mail\MailManager::class);
        $failingMailer->shouldReceive('getDefaultDriver')->andReturn('log');
        $failingMailer->shouldReceive('to')->andThrow(new \RuntimeException('Simulated transport failure'));
        Mail::swap($failingMailer);
        $this->post("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
        $notification = DB::table('association_payment_notifications')->where('payment_id', $payment)->where('event_key', 'activated')->first();
        PaymentNotification::deliver($notification->id);
        $this->assertDatabaseHas('association_payment_notifications', [
            'id' => $notification->id, 'sent_at' => null, 'last_error' => 'Email delivery failed. Retry delivery.',
        ]);
        $this->assertDatabaseHas('association_payment_requests', ['id' => $payment, 'status' => 'acknowledged']);
        $this->assertDatabaseCount('association_memberships', 1);
        $this->assertDatabaseCount('association_payment_receipts', 1);
        Mail::fake();
        PaymentNotification::deliver($notification->id);
        Mail::assertSentCount(1);
        $this->assertNotNull(DB::table('association_payment_notifications')->where('id', $notification->id)->value('sent_at'));
    }

    private function proof(string $reference): array
    {
        return [
            'reference' => $reference, 'paid_on' => now()->format('Y-m-d'),
            'proof' => UploadedFile::fake()->create('payment.pdf', 20, 'application/pdf'),
        ];
    }

    private function association(User $owner): string
    {
        $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert([
            'id' => $id, 'applicant_id' => $owner->id, 'name' => 'Payment Test Association', 'name_key' => $id,
            'organisation_type' => 'Association', 'industry' => 'Tamil', 'city' => 'Chennai',
            'representative_role' => 'Secretary', 'status' => 'approved', 'official_contact_email' => 'office@example.org',
            'registered_office_address' => '1 Main Road, Chennai', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('association_workspace_settings')->insert([
            'association_id' => $id, 'display_name' => 'Payment Test Association', 'accent' => '#b9d66b',
            'fields' => '[]', 'version' => 0, 'yearly_fee_minor' => 10025, 'lifetime_fee_minor' => 50050,
            'payment_instructions' => 'Pay by bank transfer.',
        ]);

        return $id;
    }

    private function application(string $association, User $member, ?string $type = 'yearly', ?int $fee = 10025): string
    {
        $id = (string) Str::uuid();
        DB::table('association_membership_applications')->insert([
            'id' => $id, 'association_id' => $association, 'member_id' => $member->id,
            'membership_type' => $type, 'fee_minor' => $fee, 'currency' => 'INR',
            'statement' => 'My professional experience', 'status' => 'accepted_for_payment',
            'form_snapshot' => json_encode(['name' => $member->name, 'email' => $member->email, 'answers' => []], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
