<?php

namespace App\Services\Accounting;

use App\Exceptions\BillingReminderNotSent;
use App\Mail\BillingInvoiceReminder;
use App\Support\AssociationFinance as Finance;
use App\Support\AssociationMailer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Throwable;

final class BillingInvoiceReminders
{
    public static function preview(string $association): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        return DB::table('association_bill_documents as d')
            ->join('association_finance_vouchers as v', 'v.id', '=', 'd.voucher_id')
            ->join('association_finance_accounts as a', 'a.id', '=', 'd.party_account_id')
            ->join('association_memberships as m', 'm.id', '=', 'a.membership_id')
            ->join('users as u', 'u.id', '=', 'm.member_id')
            ->where('d.association_id', $association)->where('v.association_id', $association)->where('a.association_id', $association)->where('m.association_id', $association)
            ->where('v.kind', 'invoice')->where('v.status', 'posted')->where('d.flow', 'invoice')
            ->orderBy('d.due_date')->orderBy('v.id')
            ->get(['v.id', 'v.number', 'd.due_date', 'u.id as member_id', 'u.name as member_name', 'u.email', 'u.email_verified_at'])
            ->map(function ($invoice) use ($today) {
                $invoice->outstanding = BillSettlementService::remaining($invoice->id);
                $face = (string) DB::selectOne('select filmunity_bill_amount(?)::text as amount', [$invoice->id])->amount;
                $paymentState = bccomp($invoice->outstanding, $face, 2) < 0 ? 'partially paid' : 'unpaid';
                $invoice->recipient = strtolower(trim($invoice->email));
                $invoice->eligible = $invoice->email_verified_at !== null && filter_var($invoice->recipient, FILTER_VALIDATE_EMAIL) !== false;
                $invoice->reason = 'Posted '.$paymentState.' invoice with an outstanding balance; '.($invoice->due_date < $today ? 'overdue' : ($invoice->due_date === $today ? 'due today' : 'not yet due'));
                if (! $invoice->eligible) {
                    $invoice->reason .= '. Dispatch unavailable: member email is invalid or unverified.';
                }
                unset($invoice->email, $invoice->email_verified_at);

                return $invoice;
            })->filter(fn ($invoice) => bccomp($invoice->outstanding, '0', 2) > 0)->values()->all();
    }

    public static function request(string $association, Authenticatable $actor, string $voucher): string
    {
        Finance::owner($actor, $association);

        return DB::transaction(function () use ($association, $actor, $voucher) {
            Finance::lock($association);
            Finance::owner($actor, $association);
            $invoice = collect(self::preview($association))->firstWhere('id', $voucher);
            Finance::check($invoice !== null && $invoice->eligible, 'Choose a posted outstanding invoice with a verified member recipient.');
            $day = CarbonImmutable::now(config('app.timezone'))->toDateString();
            $existing = DB::table('association_invoice_reminders')->where('association_id', $association)->where('voucher_id', $voucher)->where('recipient', $invoice->recipient)->where('reminder_on', $day)->first();
            if ($existing) {
                return $existing->id;
            }
            $id = (string) Str::uuid();
            DB::table('association_invoice_reminders')->insert(['id' => $id, 'association_id' => $association, 'voucher_id' => $voucher, 'recipient' => $invoice->recipient, 'member_id' => $invoice->member_id, 'reminder_on' => $day, 'requested_by' => $actor->getAuthIdentifier(), 'snapshot' => json_encode($invoice, JSON_THROW_ON_ERROR)]);
            Finance::event($association, $actor, 'invoice_reminder_requested', $voucher, json_encode(['reminder' => $id, 'occurrence' => $day], JSON_THROW_ON_ERROR));

            return $id;
        });
    }

    public static function dispatch(string $association, Authenticatable $actor, string $id): void
    {
        Finance::owner($actor, $association);
        DB::transaction(function () use ($association, $actor, $id) {
            Finance::lock($association);
            Finance::owner($actor, $association);
            $notice = DB::table('association_invoice_reminders')->where('association_id', $association)->where('id', $id)->lockForUpdate()->first();
            abort_unless($notice, 404);
            if (in_array($notice->status, ['simulated', 'cancelled', 'sent', 'skipped', 'queued', 'sending', 'uncertain'], true)) {
                return;
            }
            $invoice = collect(self::preview($association))->firstWhere('id', $notice->voucher_id);
            $values = ['attempts' => $notice->attempts + 1, 'attempted_at' => now(), 'updated_at' => now()];
            $day = CarbonImmutable::now(config('app.timezone'))->toDateString();
            if (! $invoice || ! $invoice->eligible || $invoice->recipient !== $notice->recipient || (int) $invoice->member_id !== (int) $notice->member_id || $notice->reminder_on !== $day) {
                $values += ['status' => 'cancelled', 'last_error' => 'Invoice, balance, recipient or daily occurrence is no longer eligible.'];
            } elseif (! app()->environment('testing') || ! config('billing-reminders.testing_delivery') || ! (Mail::getFacadeRoot() instanceof MailFake)) {
                $values += ['status' => 'disabled', 'last_error' => 'Live sending is disabled. No message was sent.'];
            } else {
                $values['snapshot'] = json_encode($invoice, JSON_THROW_ON_ERROR);
                try {
                    AssociationMailer::send($association, $notice->recipient, new BillingInvoiceReminder((array) $invoice));
                    $values += ['status' => 'simulated', 'last_error' => null];
                } catch (Throwable $error) {
                    report($error);
                    $values += ['status' => $error instanceof BillingReminderNotSent ? 'failed' : 'uncertain', 'last_error' => $error instanceof BillingReminderNotSent ? 'Delivery confirmed not sent; retry the same occurrence.' : 'Delivery outcome uncertain; review before retry.'];
                }
            }
            DB::table('association_invoice_reminders')->where('id', $id)->update($values);
            Finance::event($association, $actor, 'invoice_reminder_outcome', $notice->voucher_id, json_encode(['reminder' => $id, 'status' => $values['status'], 'attempt' => $values['attempts'], 'reason' => $values['last_error']], JSON_THROW_ON_ERROR));
        });
    }
}
