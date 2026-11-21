<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AssociationWorkspace
{
    public static function forAdmin(Authenticatable $user): Builder
    {
        return DB::table('association_registration_applications')
            ->where('applicant_id', $user->getAuthIdentifier())
            ->where('status', 'approved');
    }
}
