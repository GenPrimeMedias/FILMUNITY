<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BoardGovernance;
use App\Support\BoardNotifications;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WorkspaceMutationRequests;
use Tests\TestCase;

class BoardManagementTest extends TestCase
{
    use DatabaseTransactions;
    use WorkspaceMutationRequests;

    public function test_board_records_are_tenant_scoped(): void
    {
        [$owner, $association] = $this->association();
        $other = User::factory()->create(['association_signup' => true]);
        $this->actingAs($owner)->get("/association-board/$association")->assertOk();
        $this->actingAs($other)->get("/association-board/$association")->assertNotFound();
    }

    public function test_setup_versions_policy_and_does_not_grant_membership_application_privileges(): void
    {
        [$owner, $association] = $this->association();
        $this->actingAs($owner);
        $this->postWithWorkspaceLease("/association-board/$association/setup", [
            'body_name' => 'Governing Board', 'body_type' => 'board',
            'positions' => [['title' => 'Secretary', 'seats' => 1, 'responsibilities' => 'Records', 'office_bearer' => true, 'voting_eligible' => true]],
            'policy' => ['effective_from' => now()->toDateString(), 'timezone' => 'Asia/Kolkata', 'quorum_type' => 'fixed_count', 'quorum_count' => 1, 'quorum_denominator' => 'eligible_seats', 'rounding_policy' => 'ceil', 'voting_rule' => 'ordinary_majority', 'notice_days' => 3, 'reviewed' => true, 'allow_external_appointees' => false],
        ])->assertRedirect();
        $this->assertDatabaseHas('association_governance_bodies', ['association_id' => $association, 'name' => 'Governing Board']);
        $this->assertDatabaseHas('association_governance_policies', ['association_id' => $association, 'status' => 'reviewed', 'version' => 1]);
        $this->actingAs($owner)->get('/membership-applications/apply')->assertForbidden();
    }

    public function test_positions_and_reviewed_policy_can_be_saved_independently(): void
    {
        [$owner, $association] = $this->association();
        $this->actingAs($owner);

        $this->postWithWorkspaceLease("/association-board/$association/setup", [
            'body_name' => 'Governing Board',
            'body_type' => 'board',
            'positions' => [['title' => 'President', 'seats' => 1, 'responsibilities' => 'Leads meetings', 'office_bearer' => true, 'voting_eligible' => true]],
            'policy' => ['effective_from' => now()->toDateString(), 'timezone' => 'Asia/Kolkata', 'quorum_type' => 'fixed_count', 'quorum_count' => 1, 'quorum_denominator' => 'eligible_seats', 'rounding_policy' => 'ceil', 'voting_rule' => 'ordinary_majority', 'notice_days' => 3, 'reviewed' => true, 'allow_external_appointees' => false],
        ])->assertRedirect();

        $body = DB::table('association_governance_bodies')->where('association_id', $association)->first();
        $policyCount = DB::table('association_governance_policies')->where('association_id', $association)->count();

        $this->postWithWorkspaceLease("/association-board/$association/positions", [
            'body_name' => 'Governing Board',
            'body_type' => 'board',
            'positions' => [
                ['title' => 'President', 'display_order' => 1, 'seats' => 1, 'responsibilities' => 'Chairs meetings', 'office_bearer' => true, 'voting_eligible' => true],
                ['title' => 'Youth observer', 'display_order' => 5, 'seats' => 1, 'responsibilities' => 'Provides advice', 'office_bearer' => false, 'voting_eligible' => false],
            ],
        ])->assertRedirect();

        $this->assertSame($policyCount, DB::table('association_governance_policies')->where('association_id', $association)->count());
        $this->assertDatabaseHas('association_governance_positions', ['association_id' => $association, 'body_id' => $body->id, 'title' => 'Youth observer', 'display_order' => 5, 'voting_eligible' => false]);

        $this->postWithWorkspaceLease("/association-board/$association/policy", [
            'body_name' => 'Governing Board',
            'policy' => [
                'effective_from' => now()->toDateString(),
                'timezone' => 'Asia/Kolkata',
                'quorum_type' => 'percentage',
                'quorum_percent' => 60,
                'quorum_denominator' => 'eligible_seats',
                'rounding_policy' => 'ceil',
                'voting_rule' => 'ordinary_majority',
                'notice_days' => 7,
                'default_term_duration' => '2_years',
                'max_consecutive_terms' => 2,
                'reviewed' => true,
                'allow_external_appointees' => false,
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('association_governance_policies', ['association_id' => $association, 'version' => 2, 'quorum_type' => 'percentage', 'quorum_percent' => 60, 'default_term_duration' => '2_years', 'max_consecutive_terms' => 2]);
    }

    public function test_seat_capacity_and_cross_association_member_reference_are_rejected(): void
    {
        [$owner, $association] = $this->association();
        $otherAssociation = (string) Str::uuid();
        DB::table('association_registration_applications')->insert(['id' => $otherAssociation, 'applicant_id' => User::factory()->create()->id, 'name' => 'Other Association', 'name_key' => $otherAssociation, 'organisation_type' => 'Association', 'industry' => 'Film', 'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $body = (string) Str::uuid();
        $position = (string) Str::uuid();
        $term = (string) Str::uuid();
        DB::table('association_governance_bodies')->insert(['id' => $body, 'association_id' => $association, 'name' => 'Board', 'body_type' => 'board', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_positions')->insert(['id' => $position, 'association_id' => $association, 'body_id' => $body, 'title' => 'President', 'seats' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_terms')->insert(['id' => $term, 'association_id' => $association, 'body_id' => $body, 'name' => '2026 Board', 'state' => 'draft', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $foreignMember = User::factory()->create();
        $this->actingAs($owner)->postWithWorkspaceLease("/association-board/$association/appointments", ['body_id' => $body, 'term_id' => $term, 'position_id' => $position, 'member_id' => $foreignMember->id, 'appointment_method' => 'appointed', 'seat_number' => 1, 'starts_on' => '2026-01-01'])->assertStatus(422);
    }

    public function test_quorum_uses_reviewed_snapshot_and_finalizes_recusal_and_resolution(): void
    {
        [$owner, $association] = $this->association();
        $body = (string) Str::uuid();
        $position = (string) Str::uuid();
        $term = (string) Str::uuid();
        $meeting = (string) Str::uuid();
        $agenda = (string) Str::uuid();
        DB::table('association_governance_bodies')->insert(['id' => $body, 'association_id' => $association, 'name' => 'Board', 'body_type' => 'board', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_positions')->insert(['id' => $position, 'association_id' => $association, 'body_id' => $body, 'title' => 'President', 'seats' => 2, 'voting_eligible' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_terms')->insert(['id' => $term, 'association_id' => $association, 'body_id' => $body, 'name' => '2026 Board', 'state' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $policy = ['status' => 'reviewed', 'quorum_type' => 'percentage', 'quorum_percent' => 50, 'quorum_denominator' => 'eligible_seats', 'rounding_policy' => 'ceil', 'voting_rule' => 'ordinary_majority'];
        DB::table('association_governance_meetings')->insert(['id' => $meeting, 'association_id' => $association, 'body_id' => $body, 'term_id' => $term, 'reference' => 'BOARD-2026-0001', 'title' => 'Quarterly meeting', 'starts_at' => now(), 'timezone' => 'Asia/Kolkata', 'policy_snapshot' => json_encode($policy, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        DB::table('association_governance_participants')->insert([
            ['id' => $first, 'association_id' => $association, 'meeting_id' => $meeting, 'capacity' => 'member', 'voting_eligible' => true, 'attendance_status' => 'present', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $second, 'association_id' => $association, 'meeting_id' => $meeting, 'capacity' => 'member', 'voting_eligible' => true, 'attendance_status' => 'present', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('association_governance_agenda_items')->insert(['id' => $agenda, 'association_id' => $association, 'meeting_id' => $meeting, 'sequence' => 1, 'subject' => 'Approve budget', 'motion_text' => 'Approve the budget.', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_votes')->insert([
            ['id' => (string) Str::uuid(), 'association_id' => $association, 'meeting_id' => $meeting, 'agenda_item_id' => $agenda, 'participant_id' => $first, 'choice' => 'for', 'recorded_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'association_id' => $association, 'meeting_id' => $meeting, 'agenda_item_id' => $agenda, 'participant_id' => $second, 'choice' => 'recused', 'recorded_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::transaction(fn () => BoardGovernance::recheckQuorum($association, $meeting, $owner->id));
        $this->assertDatabaseHas('association_governance_meetings', ['id' => $meeting, 'quorum_state' => 'passed', 'quorum_required' => 1, 'quorum_present' => 2]);
        DB::transaction(fn () => BoardGovernance::finalizeDecision($association, $meeting, $agenda, $owner->id));
        $this->assertDatabaseHas('association_governance_agenda_items', ['id' => $agenda, 'outcome' => 'carried', 'for_count' => 1, 'recused_count' => 1, 'resolution_number' => 'RES-'.now()->format('Y').'-0001']);
    }

    public function test_unreviewed_policy_cannot_pass_quorum_or_finalize_decision(): void
    {
        [$owner, $association] = $this->association();
        $body = (string) Str::uuid();
        $term = (string) Str::uuid();
        $meeting = (string) Str::uuid();
        $agenda = (string) Str::uuid();
        DB::table('association_governance_bodies')->insert(['id' => $body, 'association_id' => $association, 'name' => 'Board', 'body_type' => 'board', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_terms')->insert(['id' => $term, 'association_id' => $association, 'body_id' => $body, 'name' => '2026 Board', 'state' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_meetings')->insert(['id' => $meeting, 'association_id' => $association, 'body_id' => $body, 'term_id' => $term, 'reference' => 'BOARD-2026-0002', 'title' => 'Meeting', 'starts_at' => now(), 'timezone' => 'Asia/Kolkata', 'policy_snapshot' => json_encode(['status' => 'draft', 'quorum_type' => 'fixed_count', 'quorum_count' => 1], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_agenda_items')->insert(['id' => $agenda, 'association_id' => $association, 'meeting_id' => $meeting, 'sequence' => 1, 'subject' => 'Decision', 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => BoardGovernance::recheckQuorum($association, $meeting, $owner->id));
        $this->assertDatabaseHas('association_governance_meetings', ['id' => $meeting, 'quorum_state' => 'manual_required']);
        $this->expectException(HttpException::class);
        DB::transaction(fn () => BoardGovernance::finalizeDecision($association, $meeting, $agenda, $owner->id));
    }

    public function test_minutes_reviewer_cannot_self_approve_and_approved_history_cannot_be_overwritten(): void
    {
        [$owner, $association] = $this->association();
        $body = (string) Str::uuid();
        $term = (string) Str::uuid();
        $meeting = (string) Str::uuid();
        $preparedBy = User::factory()->create();
        DB::table('association_governance_bodies')->insert(['id' => $body, 'association_id' => $association, 'name' => 'Board', 'body_type' => 'board', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_terms')->insert(['id' => $term, 'association_id' => $association, 'body_id' => $body, 'name' => '2026 Board', 'state' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_meetings')->insert(['id' => $meeting, 'association_id' => $association, 'body_id' => $body, 'term_id' => $term, 'reference' => 'BOARD-2026-0020', 'title' => 'Minutes meeting', 'starts_at' => now(), 'timezone' => 'Asia/Kolkata', 'policy_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $version = (string) Str::uuid();
        DB::table('association_governance_minutes_versions')->insert(['id' => $version, 'association_id' => $association, 'meeting_id' => $meeting, 'version' => 1, 'state' => 'submitted', 'content' => json_encode(['summary' => 'Original'], JSON_THROW_ON_ERROR), 'content_hash' => hash('sha256', json_encode(['summary' => 'Original'], JSON_THROW_ON_ERROR)), 'prepared_by' => $preparedBy->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_reviewers')->insert(['association_id' => $association, 'user_id' => $owner->id, 'role' => 'approver', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_settings')->insert(['association_id' => $association, 'allow_self_approval' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->postWithWorkspaceLease("/association-board/$association/meetings/$meeting/minutes", ['action' => 'approve', 'content' => ['summary' => 'Tampered'], 'base_version' => 1])->assertRedirect();
        $this->assertDatabaseHas('association_governance_minutes_versions', ['meeting_id' => $meeting, 'version' => 2, 'state' => 'approved', 'content_hash' => hash('sha256', json_encode(['summary' => 'Original'], JSON_THROW_ON_ERROR))]);
        $this->actingAs($owner)->postWithWorkspaceLease("/association-board/$association/meetings/$meeting/minutes", ['action' => 'save', 'content' => ['summary' => 'Overwrite'], 'base_version' => 2])->assertStatus(422);
        $this->actingAs($owner)->postWithWorkspaceLease("/association-board/$association/meetings/$meeting/minutes", ['action' => 'amend', 'content' => ['summary' => 'Amendment'], 'base_version' => 2])->assertRedirect();
        $this->assertDatabaseHas('association_governance_minutes_versions', ['meeting_id' => $meeting, 'version' => 3, 'is_amendment' => true, 'supersedes_version_id' => DB::table('association_governance_minutes_versions')->where('meeting_id', $meeting)->where('version', 2)->value('id')]);
    }

    public function test_private_board_print_and_calendar_are_tenant_scoped_and_joining_details_are_not_exported(): void
    {
        [$owner, $association] = $this->association();
        $body = (string) Str::uuid();
        $term = (string) Str::uuid();
        $meeting = (string) Str::uuid();
        DB::table('association_governance_bodies')->insert(['id' => $body, 'association_id' => $association, 'name' => 'Board', 'body_type' => 'board', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_terms')->insert(['id' => $term, 'association_id' => $association, 'body_id' => $body, 'name' => '2026 Board', 'state' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_governance_meetings')->insert(['id' => $meeting, 'association_id' => $association, 'body_id' => $body, 'term_id' => $term, 'reference' => 'BOARD-2026-0021', 'title' => 'Private meeting', 'starts_at' => now(), 'timezone' => 'Asia/Kolkata', 'venue' => 'Room 4', 'joining_details' => 'encrypted-secret', 'policy_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->get("/association-board/$association/print/meeting?meeting=$meeting")->assertOk()->assertSee('Private meeting')->assertDontSee('encrypted-secret')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($owner)->get("/association-board/$association/meetings/$meeting/calendar")->assertOk()->assertSee('Private meeting')->assertDontSee('encrypted-secret');
        $other = User::factory()->create(['association_signup' => true]);
        $this->actingAs($other)->get("/association-board/$association/print/meeting?meeting=$meeting")->assertNotFound();
    }

    public function test_board_notifications_are_association_scoped_and_deduplicated(): void
    {
        Mail::fake();
        [$owner, $association] = $this->association();
        $recipient = User::factory()->create(['email' => 'board-recipient@example.test']);
        BoardNotifications::queue($association, 'minutes_review', 'minutes:version-1:review', $recipient->id, 'Review minutes', 'Please review the submitted minutes.');
        BoardNotifications::queue($association, 'minutes_review', 'minutes:version-1:review', $recipient->id, 'Review minutes', 'Please review the submitted minutes.');
        $this->assertDatabaseCount('association_governance_notifications', 1);
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('association_governance_notifications', ['association_id' => $association, 'recipient_id' => $recipient->id, 'state' => 'sent']);
    }

    private function association(): array
    {
        $owner = User::factory()->create(['association_signup' => true]);
        $association = (string) Str::uuid();
        DB::table('association_registration_applications')->insert(['id' => $association, 'applicant_id' => $owner->id, 'name' => 'Board Test Association', 'name_key' => $association, 'organisation_type' => 'Association', 'industry' => 'Film', 'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $association];
    }
}
