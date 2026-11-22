<?php

namespace App\Support;

use App\Mail\AssociationPaymentUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PaymentNotification
{
    public static function record(object $payment, string $event, string $subject, string $message, bool $notifyOffice = false): void
    {
        $values = get_object_vars($payment);
        if (! is_string($values['snapshot'] ?? null) || ! is_string($values['id'] ?? null)) {
            throw new \InvalidArgumentException('Payment notices require a stored payment reference and snapshot.');
        }
        $snapshot = json_decode($values['snapshot'], true, flags: JSON_THROW_ON_ERROR);
        $recipients = [$snapshot['member_email']];
        if ($notifyOffice && ! empty($snapshot['official_email'])) {
            $recipients[] = $snapshot['official_email'];
        }
        foreach (array_unique($recipients) as $recipient) {
            $id = (string) Str::uuid();
            DB::table('association_payment_notifications')->insertOrIgnore([
                'id' => $id, 'payment_id' => $values['id'], 'event_key' => $event, 'recipient' => $recipient,
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
        AssociationDelivery::perform('association_payment_notifications', $id, function ($notification) {
            $association = DB::table('association_payment_requests')->where('id', $notification->payment_id)->value('association_id');
            AssociationMailer::send($association, $notification->recipient, new AssociationPaymentUpdate($notification->subject, $notification->message, $notification->payment_id));
        }, 'Email delivery failed. Retry delivery.', null);
    }
}
