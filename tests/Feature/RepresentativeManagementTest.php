<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;
class RepresentativeManagementTest extends TestCase {
    use DatabaseTransactions;
    protected function setUp(): void { parent::setUp(); Mail::fake(); config(['association-registration.reviewer_ids' => []]); }

    public function test_representative_personal_routes_are_blocked_and_management_remains_available(): void {
        [$owner, $association] = $this->association();
        $this->actingAs($owner)->get('/dashboard?view=member')->assertOk();
        foreach (['/membership-applications', '/membership-applications/apply', '/membership-renewals', '/membership-payments', '/my-association-workspaces'] as $url) $this->get($url)->assertForbidden();
        $this->post('/membership-applications', [])->assertForbidden();
        $this->post('/membership-renewals/'.Str::uuid(), [])->assertForbidden();
        $this->post('/membership-payments/'.Str::uuid().'/submissions', [])->assertForbidden();
        $this->get('/association-workspaces')->assertInertia(fn (Page $page) => $page->where('filmunityNavigation.associationRepresentative', true)->where('filmunityNavigation.canUsePersonalMemberships', false));
        foreach (['/association-members', '/association-members?view=renewals', '/association-payments', '/association-membership-review', "/association-workspaces/$association/setup"] as $url) $this->get($url)->assertOk();
        $this->get('/membership-cards/filmunity')->assertOk();
    }

    public function test_becoming_representative_removes_personal_access_without_deleting_payment_history(): void {
        [$owner, $association] = $this->association(); $member = User::factory()->create();
        $application = $this->application($member, $association);
        $this->actingAs($member)->post("/membership-applications/$application/confirm-plan", ['membership_type' => 'yearly', 'version' => 0])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/membership-payments/request/$application", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->value('id');
        [$representative, $represented] = $this->association();
        DB::table('association_registration_applications')->where('id', $represented)->update(['applicant_id' => $member->id]);
        $this->actingAs($member)->get("/membership-payments/$payment")->assertNotFound();
        $this->post("/membership-payments/$payment/submissions", [])->assertForbidden();
        $this->post("/membership-applications/$application/retry-notices")->assertNotFound();
        $this->assertDatabaseHas('association_payment_requests', ['id' => $payment, 'member_id' => $member->id]);
        $this->actingAs($owner)->get("/membership-payments/$payment")->assertOk();
    }

    public function test_ordinary_member_keeps_personal_navigation_and_routes(): void {
        $member = User::factory()->create();
        $this->actingAs($member)->get('/membership-applications')->assertInertia(fn (Page $page) => $page->where('filmunityNavigation.associationRepresentative', false)->where('filmunityNavigation.canUsePersonalMemberships', true));
        foreach (['/membership-applications/apply', '/membership-renewals', '/membership-payments'] as $url) $this->get($url)->assertOk();
    }

    public function test_registration_and_setup_accept_representatives_login_email_as_official_email(): void {
        Storage::fake('local');
        $owner = User::factory()->create(['association_signup' => true]);
        $this->actingAs($owner)->post('/association-registration', [
            'name' => 'Shared Mailbox Union', 'organisation_type' => 'Union', 'industry' => 'Tamil', 'city' => 'Chennai',
            'representative_role' => 'Secretary', 'registration_number' => 'TEST-2026', 'registering_authority' => 'Registrar',
            'state_or_region' => 'Tamil Nadu', 'official_contact_email' => $owner->email, 'registered_office_address' => '1 Main Road',
            'certificate' => UploadedFile::fake()->create('certificate.pdf', 30, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect('/association-registration');
        $this->assertDatabaseHas('association_registration_applications', ['applicant_id' => $owner->id, 'official_contact_email' => $owner->email, 'status' => 'pending']);
        DB::table('association_registration_applications')->where('applicant_id', $owner->id)->update(['status' => 'approved']);
        $id = DB::table('association_registration_applications')->where('applicant_id', $owner->id)->value('id');
        $this->post("/association-workspaces/$id/setup", [...$this->settings([]), 'official_contact_email' => $owner->email])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_registration_applications', ['id' => $id, 'official_contact_email' => $owner->email]);
    }

    private function plan(string $name, string $term, string $fee): array { return ['id' => (string) Str::uuid(), 'name' => $name, 'term' => $term, 'fee' => $fee, 'active' => true]; }
    private function settings(array $plans): array { return ['display_name' => 'Plan Association', 'accent' => '#b9d66b', 'version' => 0, 'remove_logo' => false,
        'yearly_fee' => '100.00', 'lifetime_fee' => '1000.00', 'payment_instructions' => 'Pay by bank transfer.', 'fields' => [], 'custom_plans' => $plans]; }
    private function association(): array {
        $owner = User::factory()->create(['association_signup' => true]); $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert(['id' => $id, 'applicant_id' => $owner->id, 'name' => 'Plan Association', 'name_key' => $id,
            'organisation_type' => 'Association', 'industry' => 'Tamil', 'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => 'approved',
            'official_contact_email' => 'office@example.org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_workspace_settings')->insert(['association_id' => $id, 'display_name' => 'Plan Association', 'accent' => '#b9d66b', 'fields' => '[]', 'version' => 0,
            'yearly_fee_minor' => 10000, 'lifetime_fee_minor' => 100000, 'payment_instructions' => 'Bank transfer.']);
        return [$owner, $id];
    }
    private function application(User $member, string $association): string {
        $id = (string) Str::uuid(); DB::table('association_membership_applications')->insert(['id' => $id, 'association_id' => $association, 'member_id' => $member->id,
            'statement' => 'Experience', 'status' => 'accepted_for_payment', 'form_snapshot' => json_encode(['name' => $member->name, 'email' => $member->email, 'answers' => []]),
            'created_at' => now(), 'updated_at' => now()]); return $id;
    }
}
