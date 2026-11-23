<?php

namespace App\Http\Controllers;

use App\Support\WorkspaceProtection as Protection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class WorkspaceProtectionController extends Controller
{
    public function status(Request $request, string $workspace): JsonResponse
    {
        Protection::owner($request, $workspace);
        $resource = $request->validate(['resource' => ['required', 'in:'.implode(',', Protection::RESOURCES)]])['resource'];
        $lease = DB::table('association_edit_leases')->where('association_id', $workspace)->where('resource', $resource)->first();
        $active = $lease?->token_hash && $lease->expires_at && now()->lt($lease->expires_at);

        Protection::pinEnabled($workspace);
        $protection = DB::table('association_workspace_protection')->where('association_id', $workspace)->first();

        return response()->json(['events' => DB::table('association_configuration_events')->where('association_id', $workspace)->orderByDesc('id')->limit(10)->get(['action', 'resource', 'actor_id', 'created_at']), 'locked_until' => $protection?->locked_until, 'configured' => (bool) $protection?->pin_hash, 'pin_enabled' => (bool) ($protection?->pin_enabled && $protection?->pin_hash), 'unlocked' => Protection::unlocked($request, $workspace), 'version' => $lease->version ?? 0, 'editor' => $active ? 'Association administrator'.($lease->user_id === $request->user()->getAuthIdentifier() ? ' (your account, another editor session)' : '') : null, 'expires_at' => $active ? $lease->expires_at : null, 'google' => (bool) $request->user()->google_id])->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, string $workspace): JsonResponse
    {
        Protection::owner($request, $workspace);
        $data = $request->validate(['password' => ['required', 'string', 'max:1024']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Account password confirmation failed. Google accounts may use Google reauthentication.']);
        }
        $request->session()->put('workspace.step_up', ['expires' => now()->addMinutes(5)->timestamp, 'user' => $request->user()->id, 'session' => hash('sha256', $request->session()->getId())]);

        return response()->json(['confirmed' => true]);
    }

    public function pin(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'regex:/^[0-9]{6}$/D', 'confirmed']]);
        Protection::setPin($request, $workspace, $data['pin']);

        return response()->json(['configured' => true]);
    }

    public function unlock(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'regex:/^[0-9]{6}$/D']]);
        if (! Protection::unlock($request, $workspace, $data['pin'])) {
            throw ValidationException::withMessages(['pin' => 'PIN unlock failed or temporarily locked. After five failures wait 15 minutes, or use authenticated reset.']);
        }

        return response()->json(['unlocked' => true, 'expires_in' => 600]);
    }

    public function disable(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'regex:/^[0-9]{6}$/D']]);
        Protection::disable($request, $workspace, $data['pin']);

        return response()->json(['pin_enabled' => false]);
    }

    public function enable(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'regex:/^[0-9]{6}$/D']]);
        Protection::enable($request, $workspace, $data['pin']);

        return response()->json(['pin_enabled' => true]);
    }

    public function lease(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate(['resource' => ['required', 'in:'.implode(',', Protection::RESOURCES)], 'operation' => ['required', 'in:acquire,heartbeat,release'], 'token' => ['nullable', 'string', 'max:100'], 'version' => ['required_if:operation,acquire', 'integer', 'min:0']]);
        Protection::requireUnlock($request, $workspace);
        if ($data['operation'] === 'acquire') {
            return response()->json(Protection::acquire($request, $workspace, $data['resource']));
        }

        return DB::transaction(function () use ($request, $workspace, $data) {
            DB::table('association_workspace_protection')->where('association_id', $workspace)->lockForUpdate()->first();
            Protection::requireUnlock($request, $workspace);
            $row = Protection::leaseRow($workspace, $data['resource']);
            abort_unless(Protection::ownsLease($request, $row, $data['token'] ?? ''), 409, 'Edit lease expired or replaced; reload and acquire a new lease.');
            DB::table('association_edit_leases')->where('association_id', $workspace)->where('resource', $data['resource'])->update($data['operation'] === 'release' ? ['token_hash' => null, 'expires_at' => null] : ['expires_at' => now()->addSeconds(90)->toIso8601String()]);
            if ($data['operation'] === 'release') {
                Protection::event($request, $workspace, 'edit_lease_released', $data['resource']);
            }

            return response()->json(['ok' => true, 'expires_in' => 90]);
        });
    }
}
