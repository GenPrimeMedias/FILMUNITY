<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

final class FilmunityReviewer
{
    public static function allows(Authenticatable $user): bool
    {
        return in_array(
            (string) $user->getAuthIdentifier(),
            config('association-registration.reviewer_ids', []),
            true,
        );
    }
}
