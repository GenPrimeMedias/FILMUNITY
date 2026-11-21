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

class AssociationRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registration_is_private_and_only_another_authorized_reviewer_can_decide(): void
    {
        Storage::fake('local');
        Mail::fake();
        $applicant = User::factory()->create(['association_signup' => true]);
        $otherMember = User::factory()->create(['association_signup' => true]);
        $ordinaryMember = User::factory()->create();
        $reviewer = User::factory()->create();
        $url = '/association-registration';
        $fields = [
            'name' => 'Example Editors Union',
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
        ];

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($ordinaryMember)->get($url)->assertForbidden();
        $this->post($url, $fields)->assertForbidden();
        $this->get("$url/start")->assertRedirect('/membership-applications');
        $this->get('/membership-applications')->assertInertia(
            fn (AssertableInertia $page) => $page->where('filmunityNavigation.associationSignup', false),
        );
        $this->actingAs($applicant)->post($url, [...$fields, 'certificate' => null])->assertSessionHasErrors('certificate');
        $this->actingAs($applicant)->post($url, $fields)->assertRedirect($url);
        $this->post($url, [...$fields, 'name' => 'Different Union'])->assertSessionHasErrors('application');

        $id = DB::table('association_registration_applications')->where('applicant_id', $applicant->id)->value('id');
        $decision = "$url/$id/review";
        $certificate = "$url/$id/certificate";
        $this->get($certificate)->assertDownload();
        $this->assertCount(1, Storage::disk('local')->allFiles('association-certificates'));

        $this->actingAs($otherMember)->get($url)->assertInertia(
            fn (AssertableInertia $page) => $page->component('association-registration')->has('applications.data', 0),
        );
        $this->get("$url/review")->assertForbidden();
        $this->get($certificate)->assertNotFound();
        $this->patch($decision, ['status' => 'approved'])->assertForbidden();

        config(['association-registration.reviewer_ids' => [(string) $applicant->id, (string) $reviewer->id]]);
        $this->actingAs($applicant)->patch($decision, ['status' => 'approved'])->assertForbidden();
        $this->actingAs($reviewer)->get($certificate)->assertDownload();
        $this->patch($decision, ['status' => 'declined'])->assertSessionHasErrors('review_note');
        $this->patch($decision, ['status' => 'approved', 'review_note' => 'Verified'])->assertRedirect("$url/review");
        Mail::assertSent(\App\Mail\AssociationRegistrationApproved::class,
            fn ($mail) => $mail->hasTo('office@example.org') && ! $mail->hasTo($applicant->email),
        );
        $this->patch($decision, ['status' => 'declined', 'review_note' => 'Second decision'])->assertSessionHasErrors('status');
        $this->actingAs($applicant)->get($url)->assertInertia(
            fn (AssertableInertia $page) => $page->component('association-registration')
                ->where('applications.data.0.status', 'approved')
                ->where('applications.data.0.review_note', 'Verified'),
        );

        $this->actingAs($otherMember)->post($url, $fields)->assertRedirect($url);
        $rejected = DB::table('association_registration_applications')->where('applicant_id', $otherMember->id)->value('id');
        $this->actingAs($reviewer)->patch("$url/$rejected/review", [
            'status' => 'declined', 'review_note' => 'Certificate needs updating',
        ])->assertRedirect("$url/review");
        $this->actingAs($otherMember)->post($url, $fields)->assertRedirect($url);
        $this->assertDatabaseHas('association_registration_applications', [
            'id' => $rejected, 'status' => 'declined', 'review_note' => 'Certificate needs updating',
        ]);
        $this->assertDatabaseHas('association_registration_applications', [
            'applicant_id' => $otherMember->id, 'name' => $fields['name'], 'status' => 'pending',
        ]);
        Mail::assertSentCount(1);
        $this->actingAs($reviewer)->get("$url/review?q=$rejected&status=declined&sort=name&direction=desc")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('association-registration')
                ->where('queueFilters.q', $rejected)
                ->where('queueFilters.direction', 'desc')
                ->where('applications.total', 1)
                ->where('applications.data.0.id', $rejected)
                ->where('applications.data.0.status', 'declined'));

    }
    public function test_applicant_history_filters_and_paginates_only_its_own_records(): void
    {
        $applicant = User::factory()->create(['association_signup' => true]);
        $other = User::factory()->create(['association_signup' => true]);
        foreach (range(1, 17) as $index) {
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('association_registration_applications')->insert([
                'id' => $id, 'applicant_id' => $index === 17 ? $other->id : $applicant->id,
                'name' => sprintf('History Union %02d', $index), 'name_key' => $id,
                'organisation_type' => 'Union', 'industry' => 'Tamil', 'city' => 'Chennai',
                'representative_role' => 'Secretary', 'status' => 'declined', 'review_note' => 'Updated certificate needed',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $url = '/association-registration?q=History%20Union&status=declined&sort=name&direction=desc';
        $this->actingAs($applicant)->get($url)->assertInertia(
            fn (AssertableInertia $page) => $page->where('reviewing', false)
                ->where('applications.total', 16)->has('applications.data', 15)
                ->where('applications.data.0.name', 'History Union 16')
                ->where('applications.data.0.review_note', 'Updated certificate needed')
                ->where('queueFilters.sort', 'name'),
        );
        $this->get($url.'&page=2')->assertInertia(
            fn (AssertableInertia $page) => $page->has('applications.data', 1)
                ->where('applications.data.0.name', 'History Union 01'),
        );
        $this->get('/association-registration?q=History%20Union%2017')->assertInertia(
            fn (AssertableInertia $page) => $page->has('applications.data', 0),
        );
    }

}
