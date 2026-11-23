<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkspaceProtection
{
    public const RESOURCES = ['workspace', 'masters', 'finance-policy', 'tax-configuration', 'board'];

    public static function owner(Request $request, string $association): void
    {
        abort_unless(AssociationWorkspace::forAdmin($request->user())->where('id', $association)->exists(), 404);
    }

    public static function event(Request $request, string $association, string $action, string $resource, ?string $note = null): void
    {
        DB::table('association_configuration_events')->insert(['association_id' => $association, 'actor_id' => $request->user()->getAuthIdentifier(), 'action' => $action, 'resource' => $resource, 'note' => $note]);
    }

    public static function unlocked(Request $request, string $association): bool
    {
        $protection = DB::table('association_workspace_protection')->where('association_id', $association)->first();
        $grant = $request->session()->get('workspace.unlock.'.$association);

        return (bool) ($protection && ! $protection->pin_enabled)
            || (bool) ($protection?->pin_enabled
                && $protection?->pin_hash
                && is_array($grant)
                && ($grant['expires'] ?? 0) > now()->timestamp
                && ($grant['generation'] ?? -1) === $protection->generation
                && ($grant['user'] ?? null) === $request->user()->getAuthIdentifier()
                && ($grant['session'] ?? '') === hash('sha256', $request->session()->getId()));
    }

    public static function requireUnlock(Request $request, string $association): void
    {
        self::owner($request, $association);
        if (! self::unlocked($request, $association)) {
            throw ValidationException::withMessages(['protection' => 'Enroll and unlock the workspace PIN before protected edits.']);
        }
    }

    public static function pinEnabled(string $association): bool
    {
        return DB::transaction(function () use ($association): bool {
            $protection = DB::table('association_workspace_protection')
                ->where('association_id', $association)
                ->lockForUpdate()
                ->first(['pin_enabled', 'pin_hash', 'pin_disabled_at', 'generation']);
            if (! $protection) {
                return true;
            }
            if (! $protection->pin_enabled && $protection->pin_hash && $protection->pin_disabled_at
                && Carbon::parse($protection->pin_disabled_at)->lte(now()->subMinutes(10))) {
                DB::table('association_workspace_protection')
                    ->where('association_id', $association)
                    ->update([
                        'pin_enabled' => true,
                        'pin_disabled_at' => null,
                        'generation' => $protection->generation + 1,
                        'updated_at' => now()->toIso8601String(),
                    ]);

                return true;
            }

            return (bool) ($protection->pin_enabled && $protection->pin_hash);
        });
    }

    public static function setPin(Request $request, string $association, string $pin): void
    {
        self::owner($request, $association);
        $stepUp = $request->session()->get('workspace.step_up');
        abort_unless(is_array($stepUp) && ($stepUp['expires'] ?? 0) >= now()->timestamp && ($stepUp['user'] ?? null) === $request->user()->getAuthIdentifier() && ($stepUp['session'] ?? '') === hash('sha256', $request->session()->getId()), 403, 'Confirm your account password or reauthenticate with Google before creating/resetting the PIN.');
        DB::transaction(function () use ($request, $association, $pin) {
            DB::table('association_workspace_protection')->insertOrIgnore(['association_id' => $association, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
            $row = DB::table('association_workspace_protection')->where('association_id', $association)->lockForUpdate()->first();
            DB::table('association_workspace_protection')->where('association_id', $association)->update(['pin_hash' => Hash::make($pin), 'pin_enabled' => true, 'pin_disabled_at' => null, 'generation' => $row->generation + 1, 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => now()->toIso8601String()]);
            DB::table('association_edit_leases')->where('association_id', $association)->update(['token_hash' => null, 'expires_at' => null]);
            self::event($request, $association, $row->pin_hash ? 'pin_reset' : 'pin_created', 'workspace');
            $request->session()->forget('workspace.unlock.'.$association);
            $request->session()->forget('workspace.step_up');
        });
    }

    public static function disable(Request $request, string $association, string $pin): void
    {
        self::owner($request, $association);
        DB::transaction(function () use ($request, $association, $pin): void {
            DB::table('association_workspace_protection')->insertOrIgnore(['association_id' => $association, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('association_workspace_protection')->where('association_id', $association)->lockForUpdate()->first();
            abort_unless($row?->pin_hash, 422, 'Create a workspace PIN before disabling PIN protection.');
            abort_unless(! $row->locked_until || now()->gte($row->locked_until), 429, 'PIN attempts are temporarily locked. Try again later.');
            if (! Hash::check($pin, $row->pin_hash)) {
                $attempts = $row->failed_attempts + 1;
                DB::table('association_workspace_protection')->where('association_id', $association)->update(['failed_attempts' => $attempts, 'locked_until' => $attempts >= 5 ? now()->addMinutes(15)->toIso8601String() : null]);
                throw ValidationException::withMessages(['pin' => 'The existing workspace PIN is incorrect.']);
            }
            DB::table('association_workspace_protection')->where('association_id', $association)->update(['pin_enabled' => false, 'pin_disabled_at' => now()->toIso8601String(), 'generation' => $row->generation + 1, 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => now()->toIso8601String()]);
            DB::table('association_edit_leases')->where('association_id', $association)->update(['token_hash' => null, 'expires_at' => null]);
            self::event($request, $association, 'pin_disabled', 'workspace');
        });
    }

    public static function enable(Request $request, string $association, string $pin): void
    {
        self::owner($request, $association);
        DB::transaction(function () use ($request, $association, $pin): void {
            $row = DB::table('association_workspace_protection')
                ->where('association_id', $association)
                ->lockForUpdate()
                ->first();
            abort_unless($row?->pin_hash, 422, 'Create a workspace PIN before enabling PIN protection.');
            abort_unless(! $row->locked_until || now()->gte($row->locked_until), 429, 'PIN attempts are temporarily locked. Try again later.');
            if (! Hash::check($pin, $row->pin_hash)) {
                $attempts = $row->failed_attempts + 1;
                DB::table('association_workspace_protection')->where('association_id', $association)->update(['failed_attempts' => $attempts, 'locked_until' => $attempts >= 5 ? now()->addMinutes(15)->toIso8601String() : null]);
                throw ValidationException::withMessages(['pin' => 'The existing workspace PIN is incorrect.']);
            }
            DB::table('association_workspace_protection')
                ->where('association_id', $association)
                ->update([
                    'pin_enabled' => true,
                    'pin_disabled_at' => null,
                    'generation' => $row->generation + 1,
                    'failed_attempts' => 0,
                    'locked_until' => null,
                    'updated_at' => now()->toIso8601String(),
                ]);
            DB::table('association_edit_leases')
                ->where('association_id', $association)
                ->update(['token_hash' => null, 'expires_at' => null]);
            self::event($request, $association, 'pin_enabled', 'workspace');
            $request->session()->put('workspace.unlock.'.$association, ['generation' => $row->generation + 1, 'expires' => now()->addMinutes(10)->timestamp, 'user' => $request->user()->getAuthIdentifier(), 'session' => hash('sha256', $request->session()->getId())]);
        });
    }

    public static function unlock(Request $request, string $association, string $pin): bool
    {
        self::owner($request, $association);

        return DB::transaction(function () use ($request, $association, $pin) {
            $row = DB::table('association_workspace_protection')->where('association_id', $association)->lockForUpdate()->first();
            if (! $row?->pin_hash || ($row->locked_until && now()->lt($row->locked_until))) {
                return false;
            }
            if (! Hash::check($pin, $row->pin_hash)) {
                $attempts = $row->failed_attempts + 1;
                DB::table('association_workspace_protection')->where('association_id', $association)->update(['failed_attempts' => $attempts, 'locked_until' => $attempts >= 5 ? now()->addMinutes(15)->toIso8601String() : null]);
                self::event($request, $association, $attempts >= 5 ? 'pin_temporarily_locked' : 'pin_unlock_failed', 'workspace');

                return false;
            }
            DB::table('association_workspace_protection')->where('association_id', $association)->update(['failed_attempts' => 0, 'locked_until' => null]);
            $request->session()->put('workspace.unlock.'.$association, ['generation' => $row->generation, 'expires' => now()->addMinutes(10)->timestamp, 'user' => $request->user()->getAuthIdentifier(), 'session' => hash('sha256', $request->session()->getId())]);
            self::event($request, $association, 'pin_unlocked', 'workspace');

            return true;
        });
    }

    public static function leaseRow(string $association, string $resource): \stdClass
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
        DB::table('association_edit_leases')->insertOrIgnore(['association_id' => $association, 'resource' => $resource, 'version' => 0]);

        return DB::table('association_edit_leases')->where('association_id', $association)->where('resource', $resource)->lockForUpdate()->firstOrFail();
    }

    public static function ownsLease(Request $request, \stdClass $row, string $token): bool
    {
        return $token !== '' && $row->token_hash && hash_equals($row->token_hash, hash('sha256', $token))
            && $row->session_hash === hash('sha256', $request->session()->getId())
            && $row->user_id === $request->user()->getAuthIdentifier() && $row->expires_at && now()->lt($row->expires_at);
    }

    /** @return array{token: string, version: int, expires_in: int} */
    public static function acquire(Request $request, string $association, string $resource): array
    {
        self::requireUnlock($request, $association);

        return DB::transaction(function () use ($request, $association, $resource) {
            DB::table('association_workspace_protection')->where('association_id', $association)->lockForUpdate()->first();
            self::requireUnlock($request, $association);
            $row = self::leaseRow($association, $resource);
            abort_unless((string) $row->version === (string) $request->input('version'), 409, 'This resource changed since you opened it. Reload the saved configuration before starting another edit.');
            if ($row->token_hash && $row->expires_at && now()->lt($row->expires_at)) {
                abort_unless(self::ownsLease($request, $row, (string) $request->input('token')), 409, 'Another tab/session is editing this resource. Wait for release or expiry.');
            }
            $token = Str::random(64);
            DB::table('association_edit_leases')->where('association_id', $association)->where('resource', $resource)->update(['token_hash' => hash('sha256', $token), 'session_hash' => hash('sha256', $request->session()->getId()), 'user_id' => $request->user()->getAuthIdentifier(), 'expires_at' => now()->addSeconds(90)->toIso8601String()]);
            self::event($request, $association, 'edit_lease_acquired', $resource);

            return ['token' => $token, 'version' => $row->version, 'expires_in' => 90];
        });
    }
}
