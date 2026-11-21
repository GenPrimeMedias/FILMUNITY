<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\IndividualMembership;
use App\Support\ApplicationCommunication;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class IndividualMembershipWorkflowTest extends TestCase
{
    use DatabaseTransactions;
    protected function setUp(): void { parent::setUp(); Mail::fake(); Storage::fake('local'); config(['association-registration.reviewer_ids' => []]); }

    public function test_verified_accounts_receive_one_fu_number_and_unverified_accounts_cannot_get_cards(): void
    {
        $member = User::factory()->create();
        $one = IndividualMembership::ensure($member);
        $two = IndividualMembership::ensure($member);
        $this->assertSame($one->id, $two->id);
        $this->assertMatchesRegularExpression('/^FU-\d{10,}$/', IndividualMembership::number($one));
        $this->actingAs($member)->get('/dashboard?view=member')->assertInertia(fn (Page $page) => $page->where('filmunityMembership.number', IndividualMembership::number($one)));
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($unverified)->get('/membership-cards/filmunity')->assertRedirect('/email/verify');
        $this->assertDatabaseMissing('filmunity_individual_memberships', ['user_id' => $unverified->id]);
    }

    public function test_platform_card_has_local_qr_download_and_verification_excludes_private_fields(): void
    {
        $member = User::factory()->create(['name' => 'Card Member']);
        $summary = IndividualMembership::summary($member);
        $this->actingAs($member)->get('/membership-cards/filmunity')->assertOk()->assertSee('Card Member')->assertSee('Download card');
        $this->get('/membership-cards/filmunity?download=svg')->assertHeader('content-type', 'image/svg+xml')->assertHeader('content-disposition', 'attachment; filename="membership-card.svg"');
        $this->get('/membership-cards/filmunity?qr=verification')->assertHeader('content-type', 'image/svg+xml')->assertSee('<svg', false);
        $this->get('/membership-cards/filmunity?qr=profile')->assertNotFound();
        $this->post('/logout');
        $this->get(parse_url($summary['verification_url'], PHP_URL_PATH))->assertOk()->assertSee($summary['number'])->assertDontSee($member->email)->assertDontSee('passport_path');
        $this->get('/verify-membership/fu/'.str_repeat('A', 64))->assertNotFound();
    }

    public function test_passport_photo_is_validated_private_and_shared_only_on_authorised_cards(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->post('/membership-cards/passport-photo', ['passport' => new UploadedFile(__DIR__.'/../Fixtures/membership-passport.png', 'passport.png', 'image/png', null, true)])->assertSessionHasNoErrors();
        $record = IndividualMembership::ensure($member);
        Storage::disk('local')->assertExists($record->passport_path);
        $this->post('/membership-cards/passport-photo', ['passport' => new UploadedFile(__DIR__.'/../Fixtures/membership-square.png', 'square.png', 'image/png', null, true)])->assertSessionHasErrors('passport');
        $this->get('/membership-cards/filmunity')->assertSee('Member passport photo');
        $url = IndividualMembership::summary($member)['verification_url'];
        $this->post('/logout');
        $this->get(parse_url($url, PHP_URL_PATH))->assertDontSee('Member passport photo')->assertDontSee('data:image');
    }

    public function test_member_chooses_plan_only_after_review_and_fee_is_locked_then_payment_activates_card(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $this->actingAs($member)->post('/membership-applications', ['association_id' => $association, 'statement' => 'My work', 'membership_type' => 'lifetime', 'fee_minor' => 1])->assertSessionHasNoErrors();
        $id = DB::table('association_membership_applications')->where('member_id', $member->id)->value('id');
        $this->assertDatabaseHas('association_membership_applications', ['id' => $id, 'membership_type' => null, 'fee_minor' => null, 'currency' => null]);
        $this->post("/membership-applications/$id/confirm-plan", ['membership_type' => 'yearly', 'version' => 0])->assertSessionHasErrors('payment');
        $this->actingAs($owner)->post("/membership-payments/request/$id", [])->assertSessionHasErrors('payment');
        $this->patch("/association-membership-review/$id", ['status' => 'accepted_for_payment'])->assertSessionHasNoErrors();
        $this->actingAs($member)->post("/membership-applications/$id/confirm-plan", ['membership_type' => 'yearly', 'version' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_membership_applications', ['id' => $id, 'membership_type' => 'yearly', 'fee_minor' => 0, 'currency' => 'INR']);
        $this->actingAs($owner)->post("/membership-payments/request/$id", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $id)->value('id');
        $this->post("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
        $membership = DB::table('association_memberships')->where('payment_id', $payment)->first();
        $this->actingAs($member)->get("/membership-cards/association/$membership->id")->assertOk()->assertSee($membership->number);
        $cardToken = DB::table('association_membership_card_tokens')->where('membership_id', $membership->id)->first();
        $token = \Illuminate\Support\Facades\Crypt::decryptString($cardToken->token_encrypted);
        DB::table('association_memberships')->where('id', $membership->id)->update(['status' => 'suspended']);
        $this->get('/verify-membership/association/'.$token)->assertSee('suspended')->assertDontSee($member->email);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/membership-cards/association/$membership->id")->assertNotFound();
        $this->post('/membership-applications', ['association_id' => $association, 'statement' => 'A different user may apply'])->assertSessionHasNoErrors();
        $this->actingAs($member)->post('/membership-applications', ['association_id' => $association, 'statement' => 'Duplicate'])->assertSessionHasErrors('association_id');
    }

    public function test_official_contact_setting_is_owner_scoped_and_not_used_to_spoof_from(): void
    {
        [$owner, $association] = $this->association();
        $payload = ['official_contact_email' => 'new-office@example.org', 'display_name' => 'Identity Association', 'accent' => '#b9d66b',
            'remove_logo' => false, 'version' => 0, 'yearly_fee' => '0.00', 'lifetime_fee' => '500.00', 'fields' => []];
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post("/association-workspaces/$association/setup", $payload)->assertNotFound();
        $this->actingAs($owner)->post("/association-workspaces/$association/setup", $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_registration_applications', ['id' => $association, 'official_contact_email' => 'new-office@example.org']);
        $mail = new \App\Mail\ApplicationWorkflowNotice(['association_id' => $association, 'heading' => 'Test notice', 'body' => 'Test', 'reply_to' => 'new-office@example.org']);
        $mail->build();
        $this->assertSame(config('mail.from.address'), $mail->from[0]['address']);
        $this->assertSame('new-office@example.org', $mail->replyTo[0]['address']);
    }

    public function test_application_correspondence_is_audited_and_other_accounts_cannot_retry_it(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $this->actingAs($member)->post('/membership-applications', ['association_id' => $association, 'statement' => 'My work'])->assertSessionHasNoErrors();
        $id = DB::table('association_membership_applications')->where('member_id', $member->id)->value('id');
        $this->assertSame(2, DB::table('membership_correspondence')->where('application_id', $id)->count());
        foreach (DB::table('membership_correspondence')->where('application_id', $id)->pluck('id') as $notice) ApplicationCommunication::deliver($notice);
        $this->get('/membership-applications')->assertInertia(fn (Page $page) => $page->has('applications.data.0.communications', 1));
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post("/membership-applications/$id/retry-notices")->assertNotFound();
        $this->actingAs($owner)->post("/membership-applications/$id/retry-notices")->assertSessionHasNoErrors();
    }

    private function association(): array
    {
        $owner = User::factory()->create(['association_signup' => true]); $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert(['id' => $id, 'applicant_id' => $owner->id, 'name' => 'Identity Association', 'name_key' => $id,
            'organisation_type' => 'Association', 'industry' => 'Tamil', 'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => 'approved',
            'official_contact_email' => 'office@example.org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_workspace_settings')->insert(['association_id' => $id, 'display_name' => 'Identity Association', 'accent' => '#b9d66b', 'fields' => '[]', 'version' => 0,
            'yearly_fee_minor' => 0, 'lifetime_fee_minor' => 50000, 'payment_instructions' => 'Transfer to the office account.']);
        return [$owner, $id];
    }
}
