<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MembershipLifecycle
{
    public const RENEWAL_DAYS = 30;

    public static function status(object $membership): string
    {
        if ($membership->status !== 'active') {
            return $membership->status;
        }
        $now = CarbonImmutable::now('UTC');
        if ($now->lt(CarbonImmutable::parse($membership->starts_at))) {
            return 'not_started';
        }
        if ($membership->membership_type === 'lifetime') {
            return 'lifetime';
        }
        if ($membership->ends_at === null) {
            return 'invalid_term';
        }
        $end = CarbonImmutable::parse($membership->ends_at);
        if ($now->gte($end)) {
            return 'expired';
        }

        return $end->lte($now->addDays(self::RENEWAL_DAYS)) ? 'expiring_soon' : 'active';
    }

    public static function eligible(object $membership, string $associationStatus): bool
    {
        return $associationStatus === 'approved' && $membership->membership_type === 'yearly'
            && in_array(self::status($membership), ['expired', 'expiring_soon'], true);
    }

    public static function openPayment(string $membership): ?object
    {
        return DB::table('association_payment_requests')->where('renewal_membership_id', $membership)
            ->whereIn('status', ['requested', 'submitted'])->first(['id', 'status']);
    }

    public static function present(object $membership, string $associationStatus): object
    {
        $membership->display_status = self::status($membership);
        $membership->canEnter = $associationStatus === 'approved'
            && in_array($membership->display_status, ['active', 'expiring_soon', 'lifetime'], true);
        $membership->canRenew = self::eligible($membership, $associationStatus);
        $membership->renewal_payment = self::openPayment($membership->id);

        return $membership;
    }
}
