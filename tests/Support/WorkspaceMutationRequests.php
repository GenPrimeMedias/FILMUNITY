<?php

namespace Tests\Support;

use App\Support\AssociationWorkspace;
use Illuminate\Support\Facades\Auth;

/** Real PIN/lease preconditions for existing business-rule HTTP tests; never bypasses middleware. */
trait WorkspaceMutationRequests
{
    private array $workspaceTestLeases = [];

    protected function postWithWorkspaceLease($uri, array $data = [], array $headers = [])
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $association = null;
        $resource = null;
        if (preg_match('~^/association-workspaces/([0-9a-f-]+)/(setup|mail|mail/review)$~', $path, $matches)) {
            $association = $matches[1];
            $resource = 'workspace';
        } elseif (preg_match('~^/association-board/([0-9a-f-]+)/(setup|positions|policy|terms|appointments|meetings|actions)(?:/|$)~', $path, $matches)) {
            $association = $matches[1];
            $resource = 'board';
        } elseif (preg_match('~^/association-finance/([0-9a-f-]+)/(.*)$~', $path, $matches)) {
            $association = $matches[1];
            $resource = match (true) {
                $matches[2] === 'settings' => 'finance-policy',
                str_starts_with($matches[2], 'tax/settings') || str_starts_with($matches[2], 'tax/vendor-reviews') => 'tax-configuration',
                default => 'masters',
            };
        }
        $user = Auth::user();
        if ($association && $resource && $user?->hasVerifiedEmail() && AssociationWorkspace::forAdmin($user)->where('id', $association)->exists()) {
            $base = "/association-workspaces/$association/protection";
            $this->withCredentials();
            $status = $this->getJson($base.'?resource='.$resource)->assertOk()->json();
            $this->withCookie(config('session.cookie'), app('session')->getId());
            if (! $status['configured']) {
                $this->postJson($base.'/confirm', ['password' => 'password'])->assertOk();
                $this->postJson($base.'/pin', ['pin' => '482617', 'pin_confirmation' => '482617'])->assertOk();
            }
            if (! $status['unlocked']) {
                $this->postJson($base.'/unlock', ['pin' => '482617'])->assertOk();
            }
            $key = $user->getAuthIdentifier().'|'.$association.'|'.$resource;
            $lease = $this->postJson($base.'/lease', ['resource' => $resource, 'operation' => 'acquire', 'version' => $status['version'], 'token' => $this->workspaceTestLeases[$key] ?? ''])->assertOk()->json();
            $this->workspaceTestLeases[$key] = $lease['token'];
            $headers = [...$headers, 'X-Workspace-Lease' => $lease['token'], 'X-Workspace-Version' => (string) $lease['version']];
        }

        return $this->post($uri, $data, $headers);
    }
}
