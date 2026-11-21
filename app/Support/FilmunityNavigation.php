<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FilmunityNavigation
{
    public static function forRequest(Request $request): array
    {
        $user = $request->user();

        $workspace = $user === null ? null : AssociationWorkspace::forAdmin($user)->orderBy('id')->value('id');

        $representative = $user !== null && MembershipEligibility::representative($user);
        return [
            'associationRepresentative' => $representative,
            'canUsePersonalMemberships' => $user !== null && !$representative,
            'canApplyForMembership' => $user !== null && !MembershipEligibility::representative($user),
            'associationSignup' => (bool) $user?->association_signup,
            'canApplyForAssociation' => $user !== null && self::canApplyForAssociation($user),
            'platformAdmin' => $user !== null && FilmunityReviewer::allows($user),
            'associationAdmin' => $workspace !== null,
            'associationWorkspaceId' => $workspace,
            'associationMember' => $user !== null && !$representative && AssociationMembershipAccess::forMember($user)->exists(),
        ];
    }
    public static function canApplyForAssociation(Authenticatable $user): bool
    {
        return (bool) $user->association_signup && ! DB::table('association_registration_applications')
            ->where('applicant_id', $user->getAuthIdentifier())
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
    }
}
