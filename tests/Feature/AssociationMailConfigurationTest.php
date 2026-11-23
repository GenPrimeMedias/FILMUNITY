<?php

namespace Tests\Feature;

use App\Exceptions\AssociationMailNotSent;
use App\Mail\AssociationMembershipUpdate;
use App\Mail\BillingInvoiceReminder;
use App\Models\User;
use App\Services\Accounting\BillSettlementService;
use App\Support\ApplicationCommunication;
use App\Support\AssociationDelivery;
use App\Support\AssociationMailer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Symfony\Component\Mime\Email;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\Support\WorkspaceMutationRequests;
use Tests\TestCase;

class AssociationMailConfigurationTest extends TestCase
{
    use DatabaseTransactions, FinanceWorkflowFixtures, WorkspaceMutationRequests;

    private function configuration(): array
    {
        return ['mode' => 'smtp', 'sender_email' => 'sender@example.test', 'sender_name' => 'Synthetic association', 'reply_to' => 'reply@example.test', 'host' => '8.8.8.8', 'port' => 587, 'encryption' => 'starttls', 'username' => 'synthetic-smtp-user', 'password' => 'synthetic-not-a-real-password', 'remove_password' => false, 'provider_reviewed' => true];
    }

    public function test_credentials_are_encrypted_masked_retained_or_explicitly_removed_and_tenant_scoped(): void
    {
        [$owner, $id] = $this->association();
        $url = "/association-workspaces/$id/mail";
        $this->actingAs($owner)->postWithWorkspaceLease($url, $this->configuration())->assertSessionHasNoErrors();
        $encrypted = DB::table('association_mail_settings')->where('association_id', $id)->value('encrypted_configuration');
        $this->assertStringNotContainsString('synthetic-not-a-real-password', $encrypted);
        $this->assertStringNotContainsString('synthetic-smtp-user', $encrypted);
        $this->getJson($url)->assertOk()->assertJsonMissingPath('password')->assertJsonPath('has_password', true);
        $this->postWithWorkspaceLease($url, [...$this->configuration(), 'password' => '', 'sender_name' => 'Updated synthetic name'])->assertSessionHasNoErrors();
        $this->assertSame('synthetic-not-a-real-password', AssociationMailer::configuration($id)['password']);
        $this->postWithWorkspaceLease($url, [...$this->configuration(), 'password' => '', 'remove_password' => true])->assertSessionHasNoErrors();
        $this->assertSame('', AssociationMailer::configuration($id)['password']);
        $this->postJson($url.'/test')->assertOk()->assertJsonPath('status', 'failed');
        Mail::assertNothingSent();
        $audit = DB::table('association_configuration_events')->where('association_id', $id)->get()->toJson();
        $this->assertStringNotContainsString('synthetic-not-a-real-password', $audit);
        $this->assertStringNotContainsString('synthetic-smtp-user', $audit);
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->postJson($url.'/test')->assertNotFound();
    }

    public function test_header_injection_unverified_managed_sender_and_internal_networks_are_rejected(): void
    {
        [$owner, $id] = $this->association();
        $url = "/association-workspaces/$id/mail";
        $this->actingAs($owner)->postWithWorkspaceLease($url, [...$this->configuration(), 'sender_name' => "Injected\r\nBcc: other@example.test"])->assertSessionHasErrors('sender_name');
        $this->postWithWorkspaceLease($url, [...$this->configuration(), 'mode' => 'managed'])->assertSessionHasErrors('mail');
        foreach (['127.0.0.1', '10.1.2.3', '169.254.169.254', '100.100.100.200'] as $host) {
            try {
                AssociationMailer::validatedAddress($host, 587);
                $this->fail('Restricted SMTP allowed');
            } catch (AssociationMailNotSent $error) {
                $this->assertStringNotContainsString('synthetic-not-a-real-password', $error->getMessage());
            }
        }
        try {
            AssociationMailer::validatedAddress('8.8.8.8', 80);
            $this->fail('Web-service port allowed');
        } catch (AssociationMailNotSent) {
            $this->assertTrue(true);
        }
        config(['association-mail.private_smtp_cidrs' => ['127.0.0.1/32']]);
        $this->assertSame('127.0.0.1', AssociationMailer::validatedAddress('127.0.0.1', 587));
    }

    public function test_test_send_is_rate_limited_and_only_targets_verified_administrator(): void
    {
        [$owner, $id] = $this->association();
        $url = "/association-workspaces/$id/mail";
        $this->actingAs($owner)->postWithWorkspaceLease($url, $this->configuration())->assertSessionHasNoErrors();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($url.'/test', ['recipient' => 'ignored@example.test'])->assertOk()->assertJsonPath('status', 'test_sent');
        }
        $this->postJson($url.'/test')->assertTooManyRequests();
        Mail::assertSent(AssociationMembershipUpdate::class, fn ($mail) => $mail->hasTo($owner->email));
        Mail::assertSentCount(3);
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->get($url)->assertRedirect(route('verification.notice'));
    }

    public function test_tenant_identity_is_resolved_per_send_without_mutating_global_configuration(): void
    {
        [$first, $a] = $this->association();
        [$second, $b] = $this->association();
        $this->actingAs($first)->postWithWorkspaceLease("/association-workspaces/$a/mail", [...$this->configuration(), 'sender_email' => 'one@example.test'])->assertSessionHasNoErrors();
        $this->actingAs($second)->postWithWorkspaceLease("/association-workspaces/$b/mail", [...$this->configuration(), 'sender_email' => 'two@example.test'])->assertSessionHasNoErrors();
        $before = config('mail');
        foreach ([$a, $b, $a] as $association) {
            AssociationMailer::send($association, 'recipient@example.test', new AssociationMembershipUpdate($association, 'Synthetic heading', 'Synthetic body'));
        }
        Mail::assertSentCount(3);
        Mail::assertSent(AssociationMembershipUpdate::class, function ($mail) use ($a) {
            $email = new Email;
            foreach ($mail->callbacks as $callback) {
                $callback($email);
            }

            return $email->getFrom()[0]->getAddress() === ($mail->associationId === $a ? 'one@example.test' : 'two@example.test');
        });
        $this->assertSame($before, config('mail'));
    }

    public function test_uncertain_application_delivery_requires_review_before_explicit_retry(): void
    {
        [$owner, $id] = $this->association();
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$id/mail", $this->configuration())->assertSessionHasNoErrors();
        $member = User::factory()->create();
        $application = (string) Str::uuid();
        DB::table('association_membership_applications')->insert(['id' => $application, 'association_id' => $id, 'member_id' => $member->id, 'statement' => 'Synthetic statement', 'status' => 'pending', 'form_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $fake = new class(Mail::getFacadeRoot()->manager) extends MailFake
        {
            public function send($view, array $data = [], $callback = null)
            {
                parent::send($view, $data, $callback);
                throw new \RuntimeException('Synthetic acknowledgement lost');
            }
        };
        Mail::swap($fake);
        ApplicationCommunication::record($application, 'submitted');
        $notice = DB::table('membership_correspondence')->where('application_id', $application)->first();
        ApplicationCommunication::deliver($notice->id);
        ApplicationCommunication::deliver($notice->id);
        Mail::assertSentCount(2);
        $this->assertDatabaseHas('membership_correspondence', ['id' => $notice->id, 'delivery_state' => 'uncertain', 'attempts' => 1]);
        $this->postWithWorkspaceLease("/association-workspaces/$id/mail/review", ['kind' => 'application', 'id' => $notice->id, 'decision' => 'retry', 'reason' => 'Synthetic verified non-delivery evidence'])->assertSessionHasNoErrors();
        Mail::fake();
        ApplicationCommunication::deliver($notice->id);
        ApplicationCommunication::deliver($notice->id);
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('membership_correspondence', ['id' => $notice->id, 'delivery_state' => 'sent', 'attempts' => 2]);
    }

    public function test_queued_invoice_delivery_resolves_tenant_configuration_in_the_worker(): void
    {
        [$owner, $id] = $this->association(true);
        [$party] = $this->member($owner, $id);
        $this->actingAs($owner)->postWithWorkspaceLease("/association-workspaces/$id/mail", $this->configuration())->assertSessionHasNoErrors();
        $invoice = BillSettlementService::save($id, $owner, ['client_token' => (string) Str::uuid(), 'flow' => 'invoice', 'party_account_id' => $party, 'counter_account_id' => DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', 'yearly')->value('id'), 'entry_date' => '2026-11-20', 'due_date' => '2026-11-25', 'amount' => '100.00', 'description' => 'Synthetic queued invoice']);
        $this->postTax($owner, $id, $invoice);
        config(['billing-reminders.testing_delivery' => true, 'queue.connections.database.connection' => 'pgsql']);
        $before = config('mail');
        $this->post("/association-finance/$id/billing/reminders", ['voucher_id' => $invoice, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('synthetic-not-a-real-password', DB::table('jobs')->get()->toJson());
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'billing-reminders', '--once' => true, '--tries' => 1, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseHas('association_invoice_reminders', ['voucher_id' => $invoice, 'status' => 'sent']);
        Mail::assertSent(BillingInvoiceReminder::class, function ($mail) {
            $email = new Email;
            foreach ($mail->callbacks as $callback) {
                $callback($email);
            }

            return $email->getFrom()[0]->getAddress() === 'sender@example.test';
        });
        $this->assertSame($before, config('mail'));
    }

    public function test_interrupted_claim_blocks_retry_and_requires_stale_claim_review(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $application = (string) Str::uuid();
        DB::table('association_membership_applications')->insert(['id' => $application, 'association_id' => $association, 'member_id' => $member->id, 'statement' => 'Synthetic statement', 'status' => 'pending', 'form_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        ApplicationCommunication::record($application, 'submitted');
        $notice = DB::table('membership_correspondence')->where('application_id', $application)->first();
        DB::table('membership_correspondence')->where('id', $notice->id)->update(['sent_at' => null, 'delivery_state' => 'sending', 'delivery_token' => (string) Str::uuid(), 'attempted_at' => now()->toIso8601String()]);
        Mail::fake();
        ApplicationCommunication::deliver($notice->id);
        Mail::assertNothingSent();
        $url = "/association-workspaces/$association/mail";
        $review = ['kind' => 'application', 'id' => $notice->id, 'decision' => 'retry', 'reason' => 'Synthetic provider confirmed non-delivery'];
        $this->actingAs($owner)->postWithWorkspaceLease($url.'/review', $review)->assertSessionHasErrors('mail');
        DB::table('membership_correspondence')->where('id', $notice->id)->update(['attempted_at' => now()->subMinutes(6)->toIso8601String()]);
        ApplicationCommunication::deliver($notice->id);
        Mail::assertNothingSent();
        $this->getJson($url)->assertOk()->assertJsonFragment(['needs_review' => true]);
        $this->actingAs(User::factory()->create())->post($url.'/review', $review)->assertNotFound();
        $this->actingAs($owner)->postWithWorkspaceLease($url.'/review', $review)->assertSessionHasNoErrors();
        ApplicationCommunication::deliver($notice->id);
        ApplicationCommunication::deliver($notice->id);
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('membership_correspondence', ['id' => $notice->id, 'delivery_state' => 'sent', 'attempts' => 2]);
    }

    public function test_late_delivery_completion_does_not_overwrite_reviewed_or_replaced_claim(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $application = (string) Str::uuid();
        DB::table('association_membership_applications')->insert(['id' => $application, 'association_id' => $association, 'member_id' => $member->id, 'statement' => 'Synthetic statement', 'status' => 'pending', 'form_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        ApplicationCommunication::record($application, 'submitted');
        $notice = DB::table('membership_correspondence')->where('application_id', $application)->first();
        DB::table('membership_correspondence')->where('id', $notice->id)->update(['sent_at' => null, 'delivery_state' => 'pending']);
        $replacement = (string) Str::uuid();
        AssociationDelivery::perform('membership_correspondence', $notice->id, function ($claim) use ($replacement) {
            $this->assertDatabaseHas('membership_correspondence', ['id' => $claim->id, 'delivery_state' => 'sending', 'delivery_token' => $claim->delivery_token]);
            DB::table('membership_correspondence')->where('id', $claim->id)->update(['delivery_state' => 'closed', 'delivery_token' => $replacement]);
        }, 'Synthetic known failure');
        $this->assertDatabaseHas('membership_correspondence', ['id' => $notice->id, 'delivery_state' => 'closed', 'delivery_token' => $replacement, 'sent_at' => null]);
    }
}
