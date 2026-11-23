<?php

namespace App\Support;

use App\Exceptions\AssociationMailNotSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Uses existing occurrence logs; no parallel delivery-history table. */
final class AssociationDelivery
{
    /**
     * @param  callable(\stdClass): void  $send
     * @param  (callable(\stdClass): bool)|null  $eligible
     */
    public static function perform(string $table, string $id, callable $send, string $knownFailureMessage, ?int $maximum = 5, ?callable $eligible = null): void
    {
        if (! in_array($table, ['association_payment_notifications', 'membership_correspondence', 'association_membership_reminders'], true)) {
            throw new \InvalidArgumentException('Unsupported delivery occurrence log.');
        }
        $row = DB::transaction(function () use ($table, $id, $maximum, $eligible) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->sent_at || in_array($row->delivery_state, ['sending', 'uncertain', 'closed', 'sent'], true) || ($maximum !== null && $row->attempts >= $maximum)) {
                return null;
            }
            if ($eligible && ! $eligible($row)) {
                DB::table($table)->where('id', $id)->update(['delivery_state' => 'closed', 'status' => 'cancelled', 'last_error' => 'Membership is no longer in the reminder window.', 'updated_at' => now()->toIso8601String()]);

                return null;
            }
            $row->delivery_token = (string) Str::uuid();
            DB::table($table)->where('id', $id)->update(['delivery_token' => $row->delivery_token, 'delivery_state' => 'sending', 'attempts' => $row->attempts + 1, 'attempted_at' => now()->toIso8601String(), 'last_error' => null]);

            return $row;
        });
        if (! $row) {
            return;
        }
        // In production the claim above commits before network I/O. A killed worker leaves a durable sending state.
        $state = 'sent';
        $error = null;
        try {
            $send($row);
        } catch (AssociationMailNotSent) {
            $state = 'failed';
            $error = $knownFailureMessage;
        } catch (\Throwable) {
            $state = 'uncertain';
            $error = 'Delivery outcome uncertain. Association administrator must review provider evidence before another send.';
        }
        $values = ['delivery_state' => $state, 'last_error' => $error, 'sent_at' => $state === 'sent' ? now()->toIso8601String() : null];
        if ($table === 'association_membership_reminders') {
            $values += ['status' => $state === 'sent' ? 'sent' : 'failed', 'updated_at' => now()->toIso8601String()];
        }
        DB::table($table)->where('id', $id)->where('delivery_state', 'sending')->where('delivery_token', $row->delivery_token)->update($values);
    }
}
