<?php
namespace App\Support;

use App\Mail\MembershipRenewalReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class MembershipReminder
{
    public static function latest(string $membership, string $expiry): ?object
    {
        return DB::table('association_membership_reminders')->where('membership_id', $membership)->where('expires_at', $expiry)
            ->orderByDesc('created_at')->first(['id', 'status', 'sent_at', 'attempts', 'last_error', 'reminder_on']);
    }

    public static function deliver(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $notice = DB::table('association_membership_reminders')->where('id', $id)->lockForUpdate()->first();
            if ($notice === null || in_array($notice->status, ['sent', 'cancelled'], true) || $notice->attempts >= 5 || $notice->delivery_state === 'uncertain') return;
            $membership = DB::table('association_memberships')->where('id', $notice->membership_id)->first();
            $associationStatus = DB::table('association_registration_applications')->where('id', $notice->association_id)->value('status');
            if ($membership === null || $membership->ends_at === null
                || !CarbonImmutable::parse($membership->ends_at)->equalTo(CarbonImmutable::parse($notice->expires_at))
                || MembershipLifecycle::status($membership) !== 'expiring_soon' || $associationStatus !== 'approved') {
                DB::table('association_membership_reminders')->where('id', $id)->update(['status' => 'cancelled', 'last_error' => 'Membership is no longer in the reminder window.', 'updated_at' => now()->toIso8601String()]);
                return;
            }
            $values = ['attempts' => $notice->attempts + 1, 'updated_at' => now()->toIso8601String()];
            try {
                AssociationMailer::send($notice->association_id, $notice->recipient, new MembershipRenewalReminder(json_decode($notice->snapshot, true, flags: JSON_THROW_ON_ERROR)));
                $values += ['status' => 'sent', 'sent_at' => now()->toIso8601String(), 'last_error' => null, 'delivery_state' => 'sent'];
            } catch (Throwable $error) {
                $known = $error instanceof \App\Exceptions\AssociationMailNotSent;
                $values += ['status' => 'failed', 'delivery_state' => $known ? 'failed' : 'uncertain', 'last_error' => $known ? 'Email delivery failed. Retry from the renewal queue.' : 'Delivery outcome uncertain. Association administrator must review provider evidence before another send.'];
            }
            DB::table('association_membership_reminders')->where('id', $id)->update($values);
        });
    }
}
