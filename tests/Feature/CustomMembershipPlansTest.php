<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AssociationPlans;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\WorkspaceMutationRequests;
use Tests\TestCase;

class CustomMembershipPlansTest extends TestCase
{
    use DatabaseTransactions;
    use WorkspaceMutationRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['association-registration.reviewer_ids' => []]);
    }

    public function test_custom_plans_are_scoped_and_defaults_remain_mandatory(): void
    {
        [$owner, $association] = $this->association();
        $custom = $this->plan('Associate', 'yearly', '150.25');
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$association/setup", $this->settings([$custom]))->assertSessionHasNoErrors();
        $this->assertCount(3, AssociationPlans::choices($association));
        $this->assertDatabaseHas('association_membership_plans', ['id' => $custom['id'], 'association_id' => $association, 'amount_minor' => 15025]);
        [$otherOwner, $other] = $this->association();
        $this->actingAs($otherOwner)->postWithWorkspaceLease("/association-workspaces/$other/setup", $this->settings([$custom]))->assertSessionHasErrors('custom_plans');
        $bad = $this->settings([$this->plan('Yearly', 'yearly', '1.00')]);
        $bad['version'] = 1;
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$association/setup", $bad)->assertSessionHasErrors('custom_plans.0.name');
        $noDefaults = $this->settings([$custom]);
        unset($noDefaults['yearly_fee']);
        $this->postWithWorkspaceLease("/association-workspaces/$association/setup", $noDefaults)->assertSessionHasErrors('yearly_fee');
    }

    public function test_selected_custom_fee_is_frozen_and_inactive_or_foreign_plans_are_rejected(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $plan = $this->plan('Associate', 'yearly', '150.25');
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$association/setup", $this->settings([$plan]))->assertSessionHasNoErrors();
        $application = $this->application($member, $association);
        $this->actingAs($member)->postWithWorkspaceLease("/membership-applications/$application/confirm-plan", ['plan_id' => $plan['id'], 'version' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_membership_applications', ['id' => $application, 'plan_id' => $plan['id'], 'fee_minor' => 15025, 'membership_type' => 'yearly']);
        $updated = $plan;
        $updated['fee'] = '250.00';
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$association/setup", [...$this->settings([$updated]), 'version' => 1])->assertSessionHasNoErrors();
        $this->postWithWorkspaceLease("/membership-payments/request/$application", ['instructions' => 'Bank'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_payment_requests', ['application_id' => $application, 'amount_minor' => 15025, 'plan_id' => $plan['id']]);
        [$otherOwner, $other] = $this->association();
        $otherMember = User::factory()->create();
        $otherApplication = $this->application($otherMember, $other);
        $this->actingAs($otherMember)->postWithWorkspaceLease("/membership-applications/$otherApplication/confirm-plan", ['plan_id' => $plan['id'], 'version' => 0])->assertSessionHasErrors('plan_id');
        $updated['active'] = false;
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$association/setup", [...$this->settings([$updated]), 'version' => 2])->assertSessionHasNoErrors();
        $newMember = User::factory()->create();
        $newApplication = $this->application($newMember, $association);
        $this->actingAs($newMember)->postWithWorkspaceLease("/membership-applications/$newApplication/confirm-plan", ['plan_id' => $plan['id'], 'version' => 3])->assertSessionHasErrors('plan_id');
        $this->assertDatabaseHas('association_payment_requests', ['application_id' => $application, 'amount_minor' => 15025]);
    }

    public function test_yearly_custom_plan_keeps_its_identity_and_uses_its_own_renewal_fee(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $plan = $this->plan('Student', 'yearly', '0.00');
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$association/setup", $this->settings([$plan]))->assertSessionHasNoErrors();
        $application = $this->application($member, $association);
        $this->actingAs($member)->postWithWorkspaceLease("/membership-applications/$application/confirm-plan", ['plan_id' => $plan['id'], 'version' => 1])->assertSessionHasNoErrors();
        $this->actingAs($owner)->postWithWorkspaceLease("/membership-payments/request/$application", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        $this->postWithWorkspaceLease("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
        $membership = DB::table('association_memberships')->where('payment_id', $payment)->first();
        $this->assertSame($plan['id'], $membership->plan_id);
        $receipt = DB::table('association_payment_receipts')->where('payment_id', $payment)->first();
        $this->assertSame('Student', json_decode($receipt->snapshot, true)['plan_name']);
        $changed = $plan;
        $changed['fee'] = '75.00';
        $this->postWithWorkspaceLease("/association-workspaces/$association/setup", [...$this->settings([$changed]), 'version' => 1])->assertSessionHasNoErrors();
        $time = CarbonImmutable::parse($membership->ends_at)->subDays(10);
        CarbonImmutable::setTestNow($time);
        Carbon::setTestNow(Carbon::parse($time->toIso8601String()));
        try {
            $this->actingAs($member)->get("/membership-renewals/$membership->id")->assertInertia(fn (Page $page) => $page->where('renewalPlan.name', 'Student')->where('renewalPlan.fee', '75.00'));
            $this->postWithWorkspaceLease("/membership-renewals/$membership->id", ['fee' => '75.00', 'version' => 2, 'end_token' => CarbonImmutable::parse($membership->ends_at)->toIso8601String(), 'confirmed' => true])->assertSessionHasNoErrors();
            $this->assertDatabaseHas('association_payment_requests', ['renewal_membership_id' => $membership->id, 'plan_id' => $plan['id'], 'amount_minor' => 7500]);
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
        $changed['term'] = 'lifetime';
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$association/setup", [...$this->settings([$changed]), 'version' => 2])->assertSessionHasErrors('custom_plans.0.term');
    }

    public function test_lifetime_custom_plan_has_no_expiry_and_preserves_name_on_card(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $plan = $this->plan('Honorary', 'lifetime', '0.00');
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$association/setup", $this->settings([$plan]))->assertSessionHasNoErrors();
        $application = $this->application($member, $association);
        $this->actingAs($member)->postWithWorkspaceLease("/membership-applications/$application/confirm-plan", ['plan_id' => $plan['id'], 'version' => 1])->assertSessionHasNoErrors();
        $this->actingAs($owner)->postWithWorkspaceLease("/membership-payments/request/$application", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        $this->postWithWorkspaceLease("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
        $membership = DB::table('association_memberships')->where('payment_id', $payment)->first();
        $this->assertNull($membership->ends_at);
        $this->actingAs($member)->get("/membership-cards/association/$membership->id")->assertSee('Honorary membership');
    }

    public function test_pending_and_approved_representatives_cannot_apply_or_confirm_any_other_association(): void
    {
        [$owner, $association] = $this->association();
        [$otherOwner, $other] = $this->association();
        $this->actingAs($owner)->get('/membership-applications/apply')->assertForbidden();
        $this->postWithWorkspaceLease('/membership-applications', ['association_id' => $other, 'statement' => 'Not allowed'])->assertForbidden();
        $application = $this->application($owner, $other);
        $this->postWithWorkspaceLease("/membership-applications/$application/confirm-plan", ['membership_type' => 'yearly', 'version' => 0])->assertForbidden();
        $this->get('/membership-applications')->assertForbidden();
        $this->get('/association-workspaces')->assertInertia(fn (Page $page) => $page->where('filmunityNavigation.canApplyForMembership', false));
        DB::table('association_registration_applications')->where('id', $association)->update(['status' => 'pending']);
        $this->postWithWorkspaceLease('/membership-applications', ['association_id' => $other, 'statement' => 'Still blocked'])->assertForbidden();
        DB::table('association_registration_applications')->where('id', $association)->update(['status' => 'declined']);
        $this->get('/membership-applications/apply')->assertOk();
    }

    private function plan(string $name, string $term, string $fee): array
    {
        return ['id' => (string) Str::uuid(), 'name' => $name, 'term' => $term, 'fee' => $fee, 'active' => true];
    }

    private function settings(array $plans): array
    {
        return ['display_name' => 'Plan Association', 'accent' => '#b9d66b', 'version' => 0, 'remove_logo' => false,
            'yearly_fee' => '100.00', 'lifetime_fee' => '1000.00', 'payment_instructions' => 'Pay by bank transfer.', 'fields' => [], 'custom_plans' => $plans];
    }

    private function association(): array
    {
        $owner = User::factory()->create(['association_signup' => true]);
        $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert(['id' => $id, 'applicant_id' => $owner->id, 'name' => 'Plan Association', 'name_key' => $id,
            'organisation_type' => 'Association', 'industry' => 'Tamil', 'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => 'approved',
            'official_contact_email' => 'office@example.org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_workspace_settings')->insert(['association_id' => $id, 'display_name' => 'Plan Association', 'accent' => '#b9d66b', 'fields' => '[]', 'version' => 0,
            'yearly_fee_minor' => 10000, 'lifetime_fee_minor' => 100000, 'payment_instructions' => 'Bank transfer.']);

        return [$owner, $id];
    }

    private function application(User $member, string $association): string
    {
        $id = (string) Str::uuid();
        DB::table('association_membership_applications')->insert(['id' => $id, 'association_id' => $association, 'member_id' => $member->id,
            'statement' => 'Experience', 'status' => 'accepted_for_payment', 'form_snapshot' => json_encode(['name' => $member->name, 'email' => $member->email, 'answers' => []]),
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
