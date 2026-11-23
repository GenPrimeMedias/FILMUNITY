<?php

namespace Tests\Feature;

use App\Mail\MembershipRenewalReminder;
use App\Models\User;
use App\Support\MembershipReminder;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class RenewalQueueAndApplyPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['association-registration.reviewer_ids' => []]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2028-06-01T10:00:00Z'));
        Carbon::setTestNow(Carbon::parse('2028-06-01T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_renewal_queue_is_strictly_scoped_to_the_next_thirty_days(): void
    {
        [$owner, $association] = $this->association();
        $due = $this->membership($owner, $association, 10);
        $boundary = $this->membership($owner, $association, 30);
        $this->membership($owner, $association, 31);
        $this->membership($owner, $association, -1);
        $this->membership($owner, $association, 0);
        $this->membership($owner, $association, 10, 'suspended');
        $this->membership($owner, $association, 10, 'active', 'lifetime');
        [$otherOwner, $other] = $this->association();
        $this->membership($otherOwner, $other, 5);
        $this->actingAs($owner)->get('/association-members?view=renewals&status=lifetime')->assertInertia(fn (Page $page) => $page
            ->where('renewalQueue', true)->where('filters.status', 'expiring_soon')->where('filters.view', 'renewals')
            ->has('memberships.data', 2)->where('memberships.data.0.id', $due->id)->where('memberships.data.1.id', $boundary->id));
        $this->get('/association-members')->assertInertia(fn (Page $page) => $page->where('renewalQueue', false)->has('memberships.data', 7));
        $this->get('/association-members?view=renewals&q=not-present')->assertInertia(fn (Page $page) => $page->has('memberships.data', 0));
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get('/association-members?view=renewals')->assertForbidden();
    }

    public function test_reminder_delivery_is_branded_audited_and_deduplicated(): void
    {
        [$owner, $association] = $this->association();
        $membership = $this->membership($owner, $association, 10);
        $url = "/association-members/$membership->id/renewal-reminder";
        $this->actingAs($owner)->from('/association-members?view=renewals')->post($url, ['confirmed' => true])->assertRedirect('/association-members?view=renewals');
        $id = DB::table('association_membership_reminders')->where('membership_id', $membership->id)->value('id');
        MembershipReminder::deliver($id);
        $this->post($url, ['confirmed' => true])->assertSessionHasNoErrors();
        MembershipReminder::deliver($id);
        $this->assertSame(1, DB::table('association_membership_reminders')->where('membership_id', $membership->id)->count());
        Mail::assertSent(MembershipRenewalReminder::class, fn ($mail) => $mail->hasTo($membership->email)
            && $mail->branding['membership_number'] === $membership->number && $mail->branding['association_name'] === 'Reminder Test Association');
        $this->assertSame(1, Mail::sent(MembershipRenewalReminder::class)->count());
        $this->assertDatabaseHas('association_membership_reminders', ['id' => $id, 'status' => 'sent']);
    }

    public function test_reminders_require_owner_confirmation_and_current_eligibility(): void
    {
        [$owner, $association] = $this->association();
        $due = $this->membership($owner, $association, 10);
        $late = $this->membership($owner, $association, 31);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post("/association-members/$due->id/renewal-reminder", ['confirmed' => true])->assertNotFound();
        $this->actingAs($owner)->post("/association-members/$due->id/renewal-reminder", [])->assertSessionHasErrors('confirmed');
        $this->post("/association-members/$late->id/renewal-reminder", ['confirmed' => true])->assertSessionHasErrors('payment');
        $this->assertSame(0, DB::table('association_membership_reminders')->count());
    }

    public function test_failed_delivery_can_retry_without_affecting_membership_and_obsolete_reminders_cancel(): void
    {
        [$owner, $association] = $this->association();
        $membership = $this->membership($owner, $association, 10);
        $mailer = \Mockery::mock(MailManager::class);
        $mailer->shouldReceive('getDefaultDriver')->andReturn('log');
        $mailer->shouldReceive('to')->andThrow(new \RuntimeException('Simulated SMTP failure'));
        Mail::swap($mailer);
        $this->actingAs($owner)->post("/association-members/$membership->id/renewal-reminder", ['confirmed' => true])->assertSessionHasNoErrors();
        $id = DB::table('association_membership_reminders')->where('membership_id', $membership->id)->value('id');
        MembershipReminder::deliver($id);
        $this->assertDatabaseHas('association_membership_reminders', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseHas('association_memberships', ['id' => $membership->id, 'status' => 'active']);
        Mail::fake();
        MembershipReminder::deliver($id);
        $this->assertDatabaseHas('association_membership_reminders', ['id' => $id, 'status' => 'sent']);
        $second = $this->membership($owner, $association, 15);
        $secondId = (string) Str::uuid();
        DB::table('association_membership_reminders')->insert([
            'id' => $secondId, 'membership_id' => $second->id, 'association_id' => $association, 'requested_by' => $owner->id,
            'expires_at' => DB::table('association_memberships')->where('id', $second->id)->value('ends_at'),
            'reminder_on' => now()->toDateString(), 'recipient' => $second->email,
            'snapshot' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Recheck at send-time must skip a member renewed since the request was queued.
        DB::table('association_memberships')->where('id', $second->id)->update(['ends_at' => now()->addYear()->toIso8601String()]);
        MembershipReminder::deliver($secondId);
        $this->assertDatabaseHas('association_membership_reminders', ['id' => $secondId, 'status' => 'cancelled']);
    }

    public function test_apply_form_and_history_are_separate_authenticated_pages(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $this->actingAs($member)->get('/membership-applications/apply')->assertInertia(fn (Page $page) => $page
            ->component('membership-apply')->has('associations', 1)->missing('applications'));
        $this->get('/membership-applications')->assertInertia(fn (Page $page) => $page
            ->component('membership-applications')->has('applications.data', 0));
        $this->actingAs($owner)->get('/membership-applications/apply')->assertForbidden();
        $this->post('/logout');
        $this->get('/membership-applications/apply')->assertRedirect('/login');
    }

    private function association(): array
    {
        $owner = User::factory()->create(['association_signup' => true]);
        $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert(['id' => $id, 'applicant_id' => $owner->id,
            'name' => 'Reminder Test Association', 'name_key' => $id, 'organisation_type' => 'Association', 'industry' => 'Tamil',
            'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => 'approved', 'official_contact_email' => 'office@example.org',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_workspace_settings')->insert(['association_id' => $id, 'display_name' => 'Reminder Test Association',
            'fields' => '[]', 'accent' => '#b9d66b', 'version' => 0, 'yearly_fee_minor' => 0, 'lifetime_fee_minor' => 0]);

        return [$owner, $id];
    }

    private function membership(User $owner, string $association, int $days, string $status = 'active', string $type = 'yearly'): object
    {
        $member = User::factory()->create();
        $id = (string) Str::uuid();
        DB::table('association_membership_applications')->insert(['id' => $id, 'association_id' => $association, 'member_id' => $member->id,
            'membership_type' => $type, 'fee_minor' => 0, 'currency' => 'INR', 'statement' => '', 'status' => 'accepted_for_payment',
            'form_snapshot' => json_encode(['name' => $member->name, 'email' => $member->email, 'answers' => []]), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->post("/membership-payments/request/$id", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $id)->value('id');
        $this->post("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
        $membership = DB::table('association_memberships')->where('payment_id', $payment)->first();
        DB::table('association_memberships')->where('id', $membership->id)->update(['status' => $status,
            'starts_at' => now()->subYear()->toIso8601String(), 'ends_at' => $type === 'lifetime' ? null : now()->addDays($days)->toIso8601String()]);
        $membership->email = $member->email;

        return $membership;
    }
}
