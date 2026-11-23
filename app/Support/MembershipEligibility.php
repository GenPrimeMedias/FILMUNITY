<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class MembershipEligibility
{
    public static function representative(Authenticatable $user): bool
    {
        return DB::table('association_registration_applications')->where('applicant_id', $user->getAuthIdentifier())->whereIn('status', ['pending', 'approved'])->exists();
    }

    public static function requireIndividual(Authenticatable $user): void
    {
        abort_if(self::representative($user), 403, 'Association representatives cannot submit or confirm membership applications. Use your association management workspace.');
    }
}
