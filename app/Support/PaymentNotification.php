<?php

namespace App\Support;

use App\Mail\AssociationPaymentUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class PaymentNotification
{
    public static function record(object $payment, string $event, string $subject, string $message, bool $notifyOffice = false): void
    {
        $snapshot = json_decode($payment->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $recipients = [$snapshot['member_email']];
        if ($notifyOffice && ! empty($snapshot['official_email'])) {
            $recipients[] = $snapshot['official_email'];
        }
        foreach (array_unique($recipients) as $recipient) {
            $id = (string) Str::uuid();
            DB::table('association_payment_notifications')->insertOrIgnore([
                'id' => $id, 'payment_id' => $payment->id, 'event_key' => $event, 'recipient' => $recipient,
                'subject' => $subject, 'message' => $message, 'created_at' => now()->toIso8601String(),
            ]);
            DB::afterCommit(function () use ($id): void {
                try {
                    self::deliver($id);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }
    }

    public static function deliver(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $notification = DB::table('association_payment_notifications')->where('id', $id)->lockForUpdate()->first();
            if ($notification === null || $notification->sent_at !== null || $notification->delivery_state === 'uncertain') {
                return;
            }
            $values = ['attempts' => $notification->attempts + 1, 'attempted_at' => now()->toIso8601String()];
            try {
                $association = DB::table('association_payment_requests')->where('id', $notification->payment_id)->value('association_id');
                AssociationMailer::send($association, $notification->recipient, new AssociationPaymentUpdate(
                    $notification->subject, $notification->message, $notification->payment_id,
                ));
                $values += ['sent_at' => now()->toIso8601String(), 'last_error' => null, 'delivery_state' => 'sent'];
            } catch (Throwable $exception) {
                $known = $exception instanceof \App\Exceptions\AssociationMailNotSent;
                $values['delivery_state'] = $known ? 'failed' : 'uncertain';
                $values['last_error'] = $known ? 'Email delivery failed. Retry delivery.' : 'Delivery outcome uncertain. Association administrator must review provider evidence before another send.';
            }
            DB::table('association_payment_notifications')->where('id', $id)->update($values);
        });
    }
}
