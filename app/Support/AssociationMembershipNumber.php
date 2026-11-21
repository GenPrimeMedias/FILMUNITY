<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssociationMembershipNumber
{
    public const MAX_NUMBER = 999999999999;

    public static function settings(string $association): array
    {
        $row = DB::table('association_membership_numbering')->where('association_id', $association)->first();
        return ['prefix' => $row?->prefix ?? 'MEM-', 'next_number' => (string) ($row?->next_number ?? 1), 'padding' => (int) ($row?->padding ?? 6)];
    }

    public static function format(string $prefix, int $number, int $padding): string
    {
        return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }

    // Caller holds the association row lock inside its database transaction.
    public static function configure(string $association, Authenticatable $actor, array $data): void
    {
        if (! array_key_exists('numbering_next', $data)) return;
        $before = self::locked($association);
        $prefix = strtoupper(trim($data['numbering_prefix'] ?? ''));
        $next = (int) $data['numbering_next'];
        $padding = (int) $data['numbering_padding'];
        if ($next < (int) $before->next_number) {
            throw ValidationException::withMessages(['numbering_next' => 'The next number cannot move backwards. Existing member numbers are never renumbered.']);
        }
        $candidate = self::format($prefix, $next, $padding);
        if (self::exists($association, $candidate)) {
            throw ValidationException::withMessages(['numbering_next' => 'That membership number is already in use. Choose a higher next number.']);
        }
        $after = ['prefix' => $prefix, 'next_number' => $next, 'padding' => $padding];
        $prior = ['prefix' => $before->prefix, 'next_number' => (int) $before->next_number, 'padding' => (int) $before->padding];
        if ($prior === $after) return;
        DB::table('association_membership_numbering')->where('association_id', $association)->update([
            ...$after, 'updated_at' => now()->toIso8601String(),
        ]);
        self::audit($association, $actor, 'configured', $prior, $after);
    }

    // All issuers, including a future import service, must take the association row lock first.
    public static function allocate(string $association, Authenticatable $actor): string
    {
        $row = self::locked($association);
        $next = (int) $row->next_number;
        AssociationPayment::requireState($next <= self::MAX_NUMBER, 'Membership numbering is exhausted. Contact the association administrator.');
        $number = self::format($row->prefix, $next, (int) $row->padding);
        AssociationPayment::requireState(! self::exists($association, $number), 'The next membership number is already in use. Update Workspace setup before acknowledging payment.');
        DB::table('association_membership_numbering')->where('association_id', $association)->update([
            'next_number' => $next + 1, 'updated_at' => now()->toIso8601String(),
        ]);
        self::audit($association, $actor, 'allocated', null, ['number' => $number, 'next_number' => $next + 1]);
        return $number;
    }

    private static function locked(string $association): object
    {
        DB::table('association_membership_numbering')->insertOrIgnore(['association_id' => $association]);
        return DB::table('association_membership_numbering')->where('association_id', $association)->lockForUpdate()->first();
    }

    private static function exists(string $association, string $number): bool
    {
        return DB::table('association_memberships')->where('association_id', $association)->whereRaw('lower(number) = lower(?)', [$number])->exists();
    }

    private static function audit(string $association, Authenticatable $actor, string $action, ?array $before, array $after): void
    {
        DB::table('association_membership_number_events')->insert([
            'association_id' => $association, 'actor_id' => $actor->getAuthIdentifier(), 'action' => $action,
            'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()->toIso8601String(),
        ]);
    }
}
