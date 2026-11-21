<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MembershipApplicationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
    }

    public function test_members_can_apply_to_multiple_approved_associations_and_owners_review_their_own_queue(): void
    {
        $member = User::factory()->create();
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $first = $this->association($firstOwner, 'approved');
        $second = $this->association($secondOwner, 'approved');
        $pending = $this->association($secondOwner, 'pending');
        $url = '/membership-applications';
        $queue = '/association-membership-review';

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($member)->post($url, ['membership_type' => 'yearly', 'association_id' => $pending, 'statement' => 'My work'])
            ->assertSessionHasErrors('association_id');

        foreach ([$first, $second] as $association) {
            $this->post($url, ['membership_type' => 'yearly', 'association_id' => $association, 'statement' => 'I would like to contribute my experience.'])
                ->assertRedirect($url);
        }
        $this->post($url, ['membership_type' => 'yearly', 'association_id' => $first, 'statement' => 'Duplicate'])->assertSessionHasErrors('association_id');
        $this->get($queue)->assertForbidden();

        $id = DB::table('association_membership_applications')->where('association_id', $first)->where('member_id', $member->id)->value('id');
        $otherId = DB::table('association_membership_applications')->where('association_id', $second)->where('member_id', $member->id)->value('id');

        $this->actingAs($secondOwner)->patch("$queue/$id", ['status' => 'accepted_for_payment'])->assertNotFound();
        $this->get("$queue?association=$first")->assertNotFound();
        $this->get($queue)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('association-membership-review')->has('applications.data', 1)
            ->where('applications.data.0.id', $otherId));
        $this->get($url)->assertForbidden();

        $this->actingAs($firstOwner)->patch("$queue/$id", ['status' => 'declined'])->assertSessionHasErrors('decision_note');
        $this->patch("$queue/$id", ['status' => 'accepted_for_payment', 'decision_note' => 'Await the fee request.'])->assertRedirect($queue);
        $this->patch("$queue/$id", ['status' => 'declined', 'decision_note' => 'Second decision'])->assertSessionHasErrors('status');
        $this->actingAs($secondOwner)->patch("$queue/$otherId", ['status' => 'declined', 'decision_note' => 'Please apply again with more detail.'])
            ->assertRedirect($queue);

        $this->actingAs($member)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('membership-applications')->has('applications.data', 2));
        $this->assertDatabaseHas('association_membership_applications', [
            'id' => $id, 'status' => 'accepted_for_payment', 'decision_note' => 'Await the fee request.',
        ]);
        $this->get("/association-workspaces/$first")->assertNotFound();
        $this->post($url, ['membership_type' => 'yearly', 'association_id' => $second, 'statement' => 'A new application with updated details.'])->assertRedirect($url);
        $this->assertDatabaseHas('association_membership_applications', ['id' => $otherId, 'status' => 'declined']);

        $this->get($url.'?status=approved&sort=submitted&direction=desc')->assertInertia(
            fn (AssertableInertia $page) => $page->where('filters.status', 'approved')
                ->has('applications.data', 1)->where('applications.data.0.id', $id),
        );
        $this->get($url.'?status=declined&q='.$otherId)->assertInertia(
            fn (AssertableInertia $page) => $page->has('applications.data', 1)->where('applications.data.0.id', $otherId),
        );
        $this->actingAs($secondOwner)->get($queue.'?'.http_build_query([
            'status' => 'pending', 'q' => strtoupper($member->email), 'association' => $second, 'sort' => 'applicant',
        ]))->assertInertia(
            fn (AssertableInertia $page) => $page->where('associationFilter', $second)
                ->has('applications.data', 1)->where('applications.data.0.status', 'pending'),
        );
        $this->get($queue.'?status=approved&q='.$id)->assertInertia(
            fn (AssertableInertia $page) => $page->has('applications.data', 0),
        );
    }

    public function test_workspace_settings_drive_only_their_own_form_and_preserve_submitted_answers(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $member = User::factory()->create();
        $association = $this->association($owner, 'approved');
        $other = $this->association($otherOwner, 'approved');
        $setup = "/association-workspaces/$association/setup";
        $url = '/membership-applications';
        $field = (string) Str::uuid();
        $settings = [
            'yearly_fee' => '100.25', 'lifetime_fee' => '500.50', 'display_name' => 'South Coast Film Editors',
            'description' => 'Membership for film editors.',
            'accent' => '#b9d66b',
            'remove_logo' => false,
            'version' => 0,
            'fields' => [[
                'id' => $field, 'label' => 'Primary language', 'type' => 'select',
                'required' => true, 'options' => ['Tamil', 'Telugu'],
            ]],
        ];

        $this->actingAs($otherOwner)->post($setup, $settings)->assertNotFound();
        $this->actingAs($owner)->get($setup)->assertOk();
        $missingFees = $settings;
        unset($missingFees['yearly_fee'], $missingFees['lifetime_fee']);
        $this->post($setup, $missingFees)->assertSessionHasErrors(['yearly_fee', 'lifetime_fee']);
        $this->post($setup, [...$settings, 'yearly_fee' => '-1', 'lifetime_fee' => '1.001'])
            ->assertSessionHasErrors(['yearly_fee', 'lifetime_fee']);
        $this->post($setup, $settings)->assertRedirect($setup);
        $this->post($setup, $settings)->assertSessionHasErrors('version');

        $this->actingAs($member)->get($setup)->assertNotFound();
        $this->get('/dashboard?panel=applications')->assertRedirect($url);
        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('membership-applications')
            ->where('associations.0.id', $association)
            ->where('associations.0.fields.0.label', 'Primary language')
            ->where('associations.1.id', $other)
            ->has('associations.1.fields', 0));

        $this->post($url, ['membership_type' => 'yearly', 'association_id' => $association, 'version' => 0, 'answers' => [$field => 'Tamil']])
            ->assertSessionHasErrors('association_id');
        $this->post($url, ['membership_type' => 'yearly', 'association_id' => $association, 'version' => 1])
            ->assertSessionHasErrors("answers.$field");
        $this->post($url, ['membership_type' => 'yearly', 'association_id' => $association, 'version' => 1, 'answers' => [$field => 'Unknown']])
            ->assertSessionHasErrors("answers.$field");
        $this->post($url, ['membership_type' => 'yearly', 'association_id' => $association, 'version' => 1, 'answers' => [$field => 'Tamil']])
            ->assertRedirect($url);

        $settings['version'] = 1;
        $settings['fields'][0]['label'] = 'Preferred language';
        $this->actingAs($owner)->post($setup, $settings)->assertRedirect($setup);
        $this->get("/association-membership-review?association=$association")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.data.0.form_snapshot.answers.0.label', 'Primary language')
                ->where('applications.data.0.form_snapshot.answers.0.value', 'Tamil')
                ->where('applications.data.0.form_snapshot.email', $member->email));
    }

    public function test_twenty_five_fields_and_private_documents_are_supported(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $applicant = User::factory()->create();
        $stranger = User::factory()->create();
        $association = $this->association($owner, 'approved');
        $file = (string) Str::uuid();
        $choice = (string) Str::uuid();
        $settings = [
            'yearly_fee' => '100.25', 'lifetime_fee' => '500.50', 'display_name' => 'Test Association', 'accent' => '#b9d66b',
            'version' => 0, 'remove_logo' => false,
            'fields' => [
                ['id' => $file, 'label' => 'Proof', 'type' => 'file', 'required' => true],
                ['id' => $choice, 'label' => 'Languages', 'type' => 'multiple', 'required' => true, 'options' => ['Tamil', 'Telugu']],
            ],
        ];
        foreach (range(1, 23) as $index) {
            $settings['fields'][] = [
                'id' => (string) Str::uuid(), 'label' => "Field $index", 'type' => 'text', 'required' => false,
            ];
        }
        $setup = "/association-workspaces/$association/setup";
        $this->actingAs($owner)->post($setup, [
            ...$settings, 'fields' => [...$settings['fields'], [
                'id' => (string) Str::uuid(), 'label' => 'Extra field', 'type' => 'text', 'required' => false,
            ]],
        ])->assertSessionHasErrors('fields');
        $this->post($setup, $settings)->assertRedirect($setup);

        $url = '/membership-applications';
        $this->actingAs($applicant)->post($url, [
            'membership_type' => 'yearly', 'association_id' => $association, 'version' => 1,
            'answers' => [
                $file => UploadedFile::fake()->create('proof.pdf', 80, 'application/pdf'),
                $choice => ['Tamil', 'Telugu'],
            ],
        ])->assertRedirect($url);
        $application = DB::table('association_membership_applications')->where('association_id', $association)->value('id');
        $document = "$url/$application/documents/$file";
        $this->get($document)->assertDownload('membership-document.pdf');
        $this->actingAs($owner)->get($document)->assertDownload();
        $this->actingAs($stranger)->get($document)->assertNotFound();
    }

    public function test_plan_is_chosen_after_approval_and_current_fee_is_retained(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $association = $this->association($owner, 'approved');
        $this->actingAs($member)->post('/membership-applications', ['association_id' => $association, 'statement' => 'My work'])->assertSessionHasNoErrors();
        $id = DB::table('association_membership_applications')->where('member_id', $member->id)->value('id');
        $this->assertDatabaseHas('association_membership_applications', ['id' => $id, 'membership_type' => null, 'fee_minor' => null]);
        $this->actingAs($owner)->patch("/association-membership-review/$id", ['status' => 'accepted_for_payment'])->assertSessionHasNoErrors();
        $this->actingAs($member)->post("/membership-applications/$id/confirm-plan", ['membership_type' => 'lifetime', 'version' => 0])->assertSessionHasNoErrors();
        DB::table('association_workspace_settings')->where('association_id', $association)->update(['lifetime_fee_minor' => 90000]);
        $this->get('/membership-applications')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('applications.data.0.fee', '500.50')->where('applications.data.0.membership_type', 'lifetime'));
    }

    private function association(User $owner, string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert([
            'id' => $id,
            'applicant_id' => $owner->id,
            'name' => "Test Union $id",
            'name_key' => $id,
            'organisation_type' => 'Union',
            'industry' => 'Tamil',
            'city' => 'Chennai',
            'representative_role' => 'Secretary',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('association_workspace_settings')->insert([
            'association_id' => $id, 'display_name' => "Test Union $id",
            'accent' => '#b9d66b', 'fields' => '[]', 'version' => 0,
            'yearly_fee_minor' => 10025, 'lifetime_fee_minor' => 50050,
        ]);

        return $id;
    }
}
