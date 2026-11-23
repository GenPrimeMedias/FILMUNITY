<?php

namespace App\Services\Accounting;

use App\Exceptions\BillingReminderNotSent;
use App\Jobs\DeliverBillingInvoiceReminder;
use App\Mail\BillingInvoiceReminder;
use App\Support\AssociationFinance as Finance;
use App\Support\AssociationMailer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Throwable;

final class BillingReminderQueue
{
    public static function enqueue(string $association, Authenticatable $actor, string $id): void
    {
        Finance::owner($actor, $association);
        DB::transaction(function () use ($association, $actor, $id) {
            Finance::lock($association);
            Finance::owner($actor, $association);
            $row = self::row($association, $id);
            Finance::check(! self::needsReview($row), 'Delivery outcome is uncertain. Review it before another send.');
            if (in_array($row->status, ['queued', 'sending', 'sent', 'simulated', 'skipped', 'cancelled'], true)) {
                return;
            }
            // The existing database queue must share the business transaction.
            Finance::check(config('queue.connections.database.driver') === 'database' && in_array(config('queue.connections.database.connection'), [null, config('database.default')], true), 'Billing reminders require the existing database queue on the application database.');
            $token = (string) Str::uuid();
            DB::table('association_invoice_reminders')->where('id', $id)->update(['status' => 'queued', 'queue_token' => $token, 'queued_at' => now(), 'last_error' => null, 'updated_at' => now()]);
            self::audit($association, $row, 'queued', 'Queued for worker recheck.', $actor->getAuthIdentifier());
            Bus::dispatch((new DeliverBillingInvoiceReminder($association, $id, $token))->onConnection('database')->onQueue('billing-reminders')->beforeCommit());
        });
    }

    public static function needsReview(object $row): bool
    {
        return $row->status === 'uncertain' || ($row->status === 'sending' && $row->attempted_at && CarbonImmutable::parse($row->attempted_at)->lt(CarbonImmutable::now()->subMinutes(5)));
    }

    private static function row(string $association, string $id): object
    {
        $row = DB::table('association_invoice_reminders')->where('association_id', $association)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
    }

    private static function audit(string $association, object $row, string $outcome, string $reason, ?int $actor = null): void
    {
        DB::table('association_finance_events')->insert(['association_id' => $association, 'voucher_id' => $row->voucher_id, 'actor_id' => $actor, 'action' => 'invoice_reminder_queue', 'note' => json_encode(['reminder' => $row->id, 'outcome' => $outcome, 'reason' => $reason, 'fake_delivery_only' => true], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public static function work(string $association, string $id, string $token): void
    {
        $claimed = DB::transaction(function () use ($association, $id, $token) {
            Finance::lock($association);
            $row = self::row($association, $id);
            if ($row->queue_token !== $token || $row->status !== 'queued') {
                return false;
            }
            DB::table('association_invoice_reminders')->where('id', $id)->update(['status' => 'sending', 'attempted_at' => now(), 'attempts' => $row->attempts + 1, 'updated_at' => now()]);
            self::audit($association, $row, 'sending', 'Worker claimed occurrence.');

            return true;
        });
        if (! $claimed) {
            return;
        }
        // The committed sending marker survives transport/worker interruption.
        DB::transaction(function () use ($association, $id, $token) {
            Finance::lock($association);
            $row = self::row($association, $id);
            if ($row->queue_token !== $token || $row->status !== 'sending') {
                return;
            }
            $invoice = collect(BillingInvoiceReminders::preview($association))->firstWhere('id', $row->voucher_id);
            $day = CarbonImmutable::now(config('app.timezone'))->toDateString();
            if (! $invoice || ! $invoice->eligible || $invoice->recipient !== $row->recipient || (int) $invoice->member_id !== (int) $row->member_id || $row->reminder_on !== $day) {
                $status = 'skipped';
                $reason = 'Invoice, outstanding balance, recipient or daily occurrence is no longer eligible.';
            } elseif (! app()->environment('testing') || ! config('billing-reminders.testing_delivery') || ! (Mail::getFacadeRoot() instanceof MailFake)) {
                $status = 'skipped';
                $reason = 'Live sending is disabled. No message was sent.';
            } else {
                try {
                    AssociationMailer::send($association, $row->recipient, new BillingInvoiceReminder((array) $invoice));
                    $status = 'sent';
                    $reason = 'Delivered to test mail fake only; no live message sent.';
                } catch (BillingReminderNotSent $error) {
                    $status = 'failed';
                    $reason = 'Transport confirmed no delivery. Retry is available.';
                    report($error);
                } catch (Throwable $error) {
                    $status = 'uncertain';
                    $reason = 'Delivery may have been accepted. Review the outcome before retry.';
                    report($error);
                }
            }
            $values = ['status' => $status, 'last_error' => $reason, 'updated_at' => now(), 'completed_at' => now()];
            if ($invoice) {
                $values['snapshot'] = json_encode($invoice, JSON_THROW_ON_ERROR);
            }
            DB::table('association_invoice_reminders')->where('id', $id)->update($values);
            self::audit($association, $row, $status, $reason);
        });
    }

    public static function interrupted(string $association, string $id, string $token): void
    {
        DB::transaction(function () use ($association, $id, $token) {
            DB::table('association_registration_applications')->where('id', $association)->lockForUpdate()->first();
            $row = self::row($association, $id);
            if ($row->queue_token !== $token || ! in_array($row->status, ['queued', 'sending'], true)) {
                return;
            }
            $status = $row->status === 'sending' ? 'uncertain' : 'failed';
            $reason = $status === 'uncertain' ? 'Worker interrupted after claim; review before retry.' : 'Worker failed before claiming delivery; retry is available.';
            DB::table('association_invoice_reminders')->where('id', $id)->update(['status' => $status, 'last_error' => $reason, 'updated_at' => now()]);
            self::audit($association, $row, $status, $reason);
        });
    }

    public static function resolve(string $association, Authenticatable $actor, string $id, bool $retry, string $reason): void
    {
        Finance::owner($actor, $association);
        DB::transaction(function () use ($association, $actor, $id, $retry, $reason) {
            Finance::lock($association);
            Finance::owner($actor, $association);
            $row = self::row($association, $id);
            Finance::check(self::needsReview($row) && trim($reason) !== '', 'Only uncertain or stale interrupted deliveries require review, with a reason.');
            $status = $retry ? 'failed' : 'skipped';
            DB::table('association_invoice_reminders')->where('id', $id)->update(['status' => $status, 'queue_token' => null, 'last_error' => $retry ? 'Reviewer confirmed no delivery: '.$reason : 'Reviewer closed without retry: '.$reason, 'updated_at' => now()]);
            self::audit($association, $row, $status, ($retry ? 'Confirmed no delivery; retry allowed. ' : 'Closed without retry. ').$reason, $actor->getAuthIdentifier());
        });
    }
}
