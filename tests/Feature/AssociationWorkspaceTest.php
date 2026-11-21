<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AssociationWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_switching_to_individual_signup_clears_association_intent(): void
    {
        $this->get('/association-registration/start')
            ->assertRedirect(route('register', ['association' => 1]))
            ->assertSessionHas('filmunity.association-registration', true);

        $this->get('/association-registration/start?mode=member')
            ->assertRedirect(route('register'))
            ->assertSessionMissing('filmunity.association-registration');
    }

    public function test_association_signup_is_persisted_when_the_account_is_created(): void
    {
        $this->get('/association-registration/start')->assertRedirect();

        $this->post(route('register'), [
            'name' => 'Association applicant',
            'email' => 'association-signup@example.com',
            'password' => 'Example-password-123',
            'password_confirmation' => 'Example-password-123',
            'association_signup' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'association-signup@example.com',
            'association_signup' => true,
        ]);
    }

    public function test_approval_opens_the_applicants_workspace_without_granting_platform_review(): void
    {
        Storage::fake('local');
        Mail::fake();
        $applicant = User::factory()->create(['association_signup' => true]);
        $stranger = User::factory()->create();
        $reviewer = User::factory()->create();
        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);

        $this->get('/association-registration/start')
            ->assertRedirect(route('register', ['association' => 1]))
            ->assertSessionHas('filmunity.association-registration', true);
        $this->actingAs($applicant)->get('/dashboard')->assertRedirect(route('association-registration.index'));
        $this->get('/dashboard')->assertRedirect(route('association-registration.index'));

        $this->post('/association-registration', [
            'name' => 'Example Cine Workers',
            'organisation_type' => 'Union',
            'industry' => 'Tamil',
            'city' => 'Chennai',
            'representative_role' => 'Secretary',
            'registration_number' => 'REG-123',
            'registering_authority' => 'Registrar of Associations',
            'state_or_region' => 'Tamil Nadu',
            'official_contact_email' => 'office@example.org',
            'registered_office_address' => '1 Main Road, Chennai',
            'certificate' => UploadedFile::fake()->create('certificate.pdf', 30, 'application/pdf'),
        ])->assertRedirect(route('association-registration.index'));
        $id = DB::table('association_registration_applications')->where('applicant_id', $applicant->id)->value('id');
        $workspaceUrl = "/association-workspaces/$id";
        $this->get($workspaceUrl)->assertNotFound();

        $this->actingAs($reviewer)->get('/dashboard')->assertRedirect(route('filmunity-admin.dashboard'));
        $this->patch("/association-registration/$id/review", ['status' => 'approved'])
            ->assertRedirect(route('association-registration.review'));
        $this->get($workspaceUrl)->assertNotFound();

        $this->actingAs($applicant)->get('/dashboard')->assertRedirect(route('association-workspaces.index'));
        $this->get('/dashboard?view=member')->assertOk();
        $this->get($workspaceUrl)->assertInertia(
            fn (AssertableInertia $page) => $page->component('association-workspaces')
                ->where('workspace.id', $id)
                ->where('filmunityNavigation.associationAdmin', true)
                ->where('filmunityNavigation.platformAdmin', false),
        );
        $this->get('/association-registration/review')->assertForbidden();
        $this->actingAs($stranger)->get($workspaceUrl)->assertNotFound();
        $this->get('/association-workspaces')->assertInertia(
            fn (AssertableInertia $page) => $page->component('association-workspaces')->has('workspaces.data', 0),
        );
    }
    public function test_workspace_directory_search_and_sort_keep_the_owner_scope(): void
    {
        $owner = User::factory()->create(['association_signup' => true]);
        $other = User::factory()->create(['association_signup' => true]);
        foreach (range(1, 3) as $index) {
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('association_registration_applications')->insert([
                'id' => $id, 'applicant_id' => $index === 3 ? $other->id : $owner->id,
                'name' => "Directory Union $index", 'name_key' => $id,
                'organisation_type' => 'Union', 'industry' => 'Tamil', 'city' => 'Chennai',
                'representative_role' => 'Secretary', 'status' => 'approved', 'review_note' => 'Verified',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($owner)->get('/association-workspaces?q=Directory%20Union&sort=name&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspaces.total', 2)->where('workspaces.data.0.name', 'Directory Union 2')
                ->where('workspaces.data.0.review_note', 'Verified'));
        $this->get('/association-workspaces?q=Directory%20Union%203')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('workspaces.data', 0));
    }

}
