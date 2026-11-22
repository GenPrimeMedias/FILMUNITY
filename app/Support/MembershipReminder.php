<?php

namespace App\Support;

use App\Mail\MembershipRenewalReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MembershipReminder
{
    public static function latest(string $membership, string $expiry): ?object
    {
        return DB::table('association_membership_reminders')->where('membership_id', $membership)->where('expires_at', $expiry)
            ->orderByDesc('created_at')->first(['id', 'status', 'sent_at', 'attempts', 'last_error', 'reminder_on']);
    }

    public static function deliver(string $id): void
    {
        AssociationDelivery::perform('association_membership_reminders', $id, function ($notice) {
            AssociationMailer::send($notice->association_id, $notice->recipient, new MembershipRenewalReminder(json_decode($notice->snapshot, true, flags: JSON_THROW_ON_ERROR)));
        }, 'Email delivery failed. Retry from the renewal queue.', 5, function ($notice) {
            if (in_array($notice->status, ['sent', 'cancelled'], true)) {
                return false;
            }
            $membership = DB::table('association_memberships')->where('id', $notice->membership_id)->first();

            return $membership !== null && $membership->ends_at !== null && CarbonImmutable::parse($membership->ends_at)->equalTo(CarbonImmutable::parse($notice->expires_at)) && MembershipLifecycle::status($membership) === 'expiring_soon' && DB::table('association_registration_applications')->where('id', $notice->association_id)->value('status') === 'approved';
        });
    }
}
