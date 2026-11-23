<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FilmunityAdminTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_entry_reuses_login_and_only_configured_reviewers_can_view_admin_pages(): void
    {
        $member = User::factory()->create();
        $reviewer = User::factory()->create();
        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);

        $this->get('/filmunity-admin/login')
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', route('filmunity-admin.dashboard'));

        $this->actingAs($member)->get('/filmunity-admin')->assertForbidden();
        $this->get('/filmunity-admin/members')->assertForbidden();

        $this->actingAs($reviewer)->get('/filmunity-admin/login')
            ->assertRedirect(route('filmunity-admin.dashboard'));
        $this->get('/filmunity-admin')->assertInertia(
            fn (AssertableInertia $page) => $page->component('filmunity-admin')->has('members')->has('pending'),
        );
        $this->get('/filmunity-admin/members')->assertInertia(
            fn (AssertableInertia $page) => $page->component('filmunity-admin-members')->has('members.data'),
        );
        $this->get('/association-registration/review?status=approved')->assertInertia(
            fn (AssertableInertia $page) => $page->component('association-registration')
                ->where('statusFilter', 'approved'),
        );

        config(['association-registration.reviewer_ids' => []]);
        $this->get('/filmunity-admin')->assertForbidden();
        $this->get('/association-registration/review')->assertForbidden();
    }

    public function test_default_dashboard_sends_reviewers_to_admin_and_preserves_member_views(): void
    {
        $member = User::factory()->create();
        $reviewer = User::factory()->create();
        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);

        $this->actingAs($member)->get('/dashboard')->assertOk();
        $this->actingAs($reviewer)->get('/dashboard')->assertRedirect(route('filmunity-admin.dashboard'));
        $this->get('/dashboard?view=member')->assertOk();
        $this->get('/dashboard?panel=profile')->assertOk();
    }

    public function test_account_search_expansion_and_documents_require_a_platform_reviewer(): void
    {
        Storage::fake('local');
        $reviewer = User::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create(['name' => 'Registered Applicant']);
        $association = (string) Str::uuid();
        $application = (string) Str::uuid();
        $field = (string) Str::uuid();
        $path = 'association-membership-documents/example.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 Example');
        DB::table('association_registration_applications')->insert([
            'id' => $association, 'applicant_id' => $owner->id, 'name' => 'Test Union',
            'name_key' => $association, 'organisation_type' => 'Union', 'industry' => 'Tamil',
            'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('association_membership_applications')->insert([
            'id' => $application, 'member_id' => $member->id, 'association_id' => $association,
            'statement' => 'My experience', 'status' => 'declined', 'decision_note' => 'Please update your proof.',
            'form_snapshot' => json_encode(['name' => $member->name, 'email' => $member->email, 'answers' => [[
                'id' => $field, 'label' => 'Proof', 'value' => 'Document uploaded', 'file_path' => $path,
            ]]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);
        $document = "/filmunity-admin/membership-applications/$application/documents/$field";
        $this->actingAs($member)->get($document)->assertForbidden();
        $this->get('/filmunity-admin/members?account='.$member->id)->assertForbidden();

        $query = http_build_query(['q' => $member->email, 'sort' => 'name', 'direction' => 'desc', 'account' => $member->id]);
        $this->actingAs($reviewer)->get('/filmunity-admin/members?'.$query)
            ->assertInertia(fn (AssertableInertia $page) => $page->has('members.data', 1)
                ->where('expanded.id', $member->id)
                ->where('expanded.applications.data.0.decision_note', 'Please update your proof.')
                ->where('expanded.applications.data.0.form_snapshot.answers.0.download_url', $document)
                ->missing('expanded.applications.data.0.form_snapshot.answers.0.file_path'));
        $this->get($document)->assertDownload('membership-document.pdf');
        config(['association-registration.reviewer_ids' => []]);
        $this->get($document)->assertForbidden();
    }

    public function test_registered_accounts_are_searchable_sorted_and_paginated(): void
    {
        $reviewer = User::factory()->create();
        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);
        foreach (range(1, 21) as $index) {
            User::factory()->create([
                'name' => sprintf('Directory Test %02d', $index),
                'email_verified_at' => null,
            ]);
        }
        $this->actingAs($reviewer)->get('/filmunity-admin/members?q=Directory%20Test&verification=unverified&sort=name&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('members.total', 21)
                ->has('members.data', 20)->where('members.data.0.name', 'Directory Test 21')
                ->where('expanded', null));
        $this->get('/filmunity-admin/members?q=Directory%20Test&verification=unverified&sort=name&direction=desc&page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('members.data', 1)
                ->where('members.data.0.name', 'Directory Test 01'));
    }
}
