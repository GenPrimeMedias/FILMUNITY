<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IndividualMembership
{
    public static function ensure(Authenticatable $user): object
    {
        abort_unless($user->email_verified_at !== null, 403);

        return DB::transaction(function () use ($user): object {
            DB::table('users')->where('id', $user->getAuthIdentifier())->lockForUpdate()->first();
            $record = DB::table('filmunity_individual_memberships')->where('user_id', $user->getAuthIdentifier())->first();
            if ($record !== null) {
                return $record;
            }
            $token = Str::random(64);
            DB::table('filmunity_individual_memberships')->insert([
                'user_id' => $user->getAuthIdentifier(), 'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token),
                'joined_at' => CarbonImmutable::parse($user->email_verified_at)->toIso8601String(), 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]);

            return DB::table('filmunity_individual_memberships')->where('user_id', $user->getAuthIdentifier())->first();
        });
    }

    public static function number(object $record): string
    {
        return 'FU-'.str_pad((string) $record->id, 10, '0', STR_PAD_LEFT);
    }

    public static function summary(Authenticatable $user): array
    {
        $record = self::ensure($user);
        $profile = DB::table('professional_profile_drafts')->where('user_id', $user->getAuthIdentifier())
            ->whereRaw("public_profile->>'username' = username")->first(['username']);

        return ['number' => self::number($record), 'joined_at' => $record->joined_at, 'status' => 'Active',
            'has_passport' => $record->passport_path !== null,
            'card_url' => url('/membership-cards/filmunity'),
            'verification_url' => url('/verify-membership/fu/'.Crypt::decryptString($record->token_encrypted)),
            'public_profile_url' => $profile ? url('/community/@'.$profile->username) : null];
    }
}
