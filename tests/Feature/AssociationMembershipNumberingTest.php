<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class AssociationMembershipNumberingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['association-registration.reviewer_ids' => []]);
    }

    public function test_owner_can_choose_prefixed_padded_or_numeric_series_and_receipt_sequence_is_separate(): void
    {
        foreach ([['SISA-', '6000325', 0, 'SISA-6000325'], ['TFPC-', '9623', 0, 'TFPC-9623'], ['', '1000', 0, '1000'], ['ABC-', '25', 6, 'ABC-000025']] as [$prefix, $next, $padding, $expected]) {
            [$owner, $association] = $this->association();
            $this->actingAs($owner)->post("/association-workspaces/$association/setup", $this->settings($prefix, $next, $padding))
                ->assertSessionHasNoErrors();
            $this->get("/association-workspaces/$association/setup")->assertInertia(fn (Page $page) => $page
                ->where('settings.numbering.prefix', $prefix)->where('settings.numbering.next_number', $next));
            $membership = $this->activate($owner, $association);
            $this->assertSame($expected, $membership->number);
            $receipt = DB::table('association_payment_receipts')->where('payment_id', $membership->payment_id)->first();
            $this->assertSame('REC-'.CarbonImmutable::now('UTC')->format('Y').'-000001', $receipt->number);
            $this->assertSame($expected, json_decode($receipt->snapshot, true)['membership_number']);
            $this->assertDatabaseHas('association_membership_numbering', ['association_id' => $association, 'next_number' => (int) $next + 1]);
            $this->assertDatabaseHas('association_membership_number_events', ['association_id' => $association, 'action' => 'allocated']);
        }
    }

    public function test_configuration_is_owner_only_and_backward_or_invalid_changes_do_not_mutate_records(): void
    {
        [$owner, $association] = $this->association();
        $stranger = User::factory()->create();
        $url = "/association-workspaces/$association/setup";
        $this->actingAs($stranger)->post($url, $this->settings('SISA-', '1000', 0))->assertNotFound();
        $this->actingAs($owner)->post($url, $this->settings('sisa-', '1000', 0))->assertSessionHasNoErrors();
        $membership = $this->activate($owner, $association);
        $this->post($url, [...$this->settings('SISA-', '999', 0), 'version' => 1])->assertSessionHasErrors('numbering_next');
        $this->post($url, [...$this->settings('BAD/', '1001', 0), 'version' => 1])->assertSessionHasErrors('numbering_prefix');
        $this->post($url, [...$this->settings('SISA-', '1001', 13), 'version' => 1])->assertSessionHasErrors('numbering_padding');
        $this->assertDatabaseHas('association_memberships', ['id' => $membership->id, 'number' => 'SISA-1000']);
        $this->assertDatabaseHas('association_membership_numbering', ['association_id' => $association, 'prefix' => 'SISA-', 'next_number' => 1001]);
        // A legacy/manual number may already reserve the proposed next value.
        DB::table('association_memberships')->where('id', $membership->id)->update(['number' => 'SISA-1001']);
        $this->post($url, [...$this->settings('SISA-', '1001', 0), 'version' => 1])->assertSessionHasErrors('numbering_next');
    }

    public function test_repeated_acknowledgement_and_renewal_never_consume_another_member_number(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $membership = $this->activate($owner, $association, $member);
        $this->post("/association-payments/$membership->payment_id/zero-fee")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_membership_numbering', ['association_id' => $association, 'next_number' => 2]);
        $nearExpiry = CarbonImmutable::parse($membership->ends_at)->subDays(10);
        CarbonImmutable::setTestNow($nearExpiry);
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($nearExpiry->toIso8601String()));
        try {
            $this->actingAs($member)->post("/membership-renewals/$membership->id", [
                'version' => 0, 'fee' => '0.00', 'end_token' => CarbonImmutable::parse($membership->ends_at)->toIso8601String(), 'confirmed' => true,
            ])->assertSessionHasNoErrors();
            $payment = DB::table('association_payment_requests')->where('renewal_membership_id', $membership->id)->value('id');
            $this->actingAs($owner)->post("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
            $this->assertDatabaseHas('association_membership_numbering', ['association_id' => $association, 'next_number' => 2]);
            $second = $this->activate($owner, $association);
            $this->assertSame('MEM-000002', $second->number);
            $this->assertSame(3, (int) DB::table('association_receipt_counters')->where('association_id', $association)->value('sequence'));
        } finally {
            CarbonImmutable::setTestNow();
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_future_prefix_changes_keep_old_memberships_and_receipts_unchanged(): void
    {
        [$owner, $association] = $this->association();
        $first = $this->activate($owner, $association);
        $snapshot = DB::table('association_payment_receipts')->where('payment_id', $first->payment_id)->value('snapshot');
        $this->post("/association-workspaces/$association/setup", $this->settings('TFPC-', '9623', 0))->assertSessionHasNoErrors();
        $second = $this->activate($owner, $association);
        $this->assertSame('TFPC-9623', $second->number);
        $this->assertDatabaseHas('association_memberships', ['id' => $first->id, 'number' => 'MEM-000001']);
        $this->assertSame($snapshot, DB::table('association_payment_receipts')->where('payment_id', $first->payment_id)->value('snapshot'));
    }

    private function settings(string $prefix, string $next, int $padding): array
    {
        return ['display_name' => 'Numbering Test Association', 'description' => '', 'payment_instructions' => '', 'accent' => '#b9d66b',
            'remove_logo' => false, 'version' => 0, 'yearly_fee' => '0.00', 'lifetime_fee' => '0.00', 'fields' => [],
            'numbering_prefix' => $prefix, 'numbering_next' => $next, 'numbering_padding' => $padding];
    }

    private function association(): array
    {
        $owner = User::factory()->create(['association_signup' => true]);
        $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert([
            'id' => $id, 'applicant_id' => $owner->id, 'name' => 'Numbering Test Association', 'name_key' => $id,
            'organisation_type' => 'Association', 'industry' => 'Tamil', 'city' => 'Chennai', 'representative_role' => 'Secretary',
            'status' => 'approved', 'official_contact_email' => 'office@example.org', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('association_workspace_settings')->insert(['association_id' => $id, 'display_name' => 'Numbering Test Association',
            'accent' => '#b9d66b', 'fields' => '[]', 'version' => 0, 'yearly_fee_minor' => 0, 'lifetime_fee_minor' => 0]);
        return [$owner, $id];
    }

    private function activate(User $owner, string $association, ?User $member = null): object
    {
        $member ??= User::factory()->create();
        $application = (string) Str::uuid();
        DB::table('association_membership_applications')->insert([
            'id' => $application, 'association_id' => $association, 'member_id' => $member->id,
            'membership_type' => 'yearly', 'fee_minor' => 0, 'currency' => 'INR', 'statement' => '', 'status' => 'accepted_for_payment',
            'form_snapshot' => json_encode(['name' => $member->name, 'email' => $member->email, 'answers' => []]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($owner)->post("/membership-payments/request/$application", [])->assertSessionHasNoErrors();
        $payment = DB::table('association_payment_requests')->where('application_id', $application)->whereNull('renewal_membership_id')->value('id');
        $this->post("/association-payments/$payment/zero-fee")->assertSessionHasNoErrors();
        return DB::table('association_memberships')->where('payment_id', $payment)->first();
    }
}
