<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AssociationMembershipAccess
{
    public static function forMember(Authenticatable $user): Builder
    {
        return DB::table('association_memberships as membership')
            ->join('association_registration_applications as association', 'association.id', '=', 'membership.association_id')
            ->where('membership.member_id', $user->getAuthIdentifier())->where('association.status', 'approved')
            ->where('membership.status', 'active')->where('membership.starts_at', '<=', now()->toIso8601String())
            ->where(function (Builder $query): void {
                $query->whereNull('membership.ends_at')->orWhere('membership.ends_at', '>', now()->toIso8601String());
            });
    }
}
