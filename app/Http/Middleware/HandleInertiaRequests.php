<?php

namespace App\Http\Middleware;

use App\Support\FilmunityBrandAssets;
use App\Support\FilmunityNavigation;
use App\Support\AssociationMembershipAccess;
use App\Support\AssociationWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'filmunityBrandAssets' => fn () => FilmunityBrandAssets::paths(),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'filmunityNavigation' => fn () => FilmunityNavigation::forRequest($request),
            'workspaceBranding' => fn () => $this->workspaceBranding($request),
        ];
    }

    /** @return array{display_name: string, logo_url: string|null}|null */
    private function workspaceBranding(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $association = $request->route('association');
        $association = is_object($association) ? ($association->id ?? null) : $association;

        if (is_string($association)) {
            $allowed = AssociationWorkspace::forAdmin($user)->where('id', $association)->exists()
                || AssociationMembershipAccess::forMember($user)->where('association.id', $association)->exists();
            $association = $allowed ? $association : null;
        }

        if (! is_string($association)) {
            $association = AssociationWorkspace::forAdmin($user)->orderBy('id')->value('id');
        }

        if (! is_string($association)) {
            $association = AssociationMembershipAccess::forMember($user)->orderBy('association.id')->value('association.id');
        }

        if (! is_string($association)) {
            return null;
        }

        $record = DB::table('association_registration_applications as registrations')
            ->leftJoin('association_workspace_settings as settings', 'settings.association_id', '=', 'registrations.id')
            ->where('registrations.id', $association)
            ->where('registrations.status', 'approved')
            ->first(['registrations.name', 'settings.display_name', 'settings.logo']);

        if ($record === null) {
            return null;
        }

        return [
            'display_name' => $record->display_name ?: $record->name,
            'logo_url' => $record->logo
                ? route('membership-applications.logo', ['association' => $association], false)
                : null,
        ];
    }
}
