<?php

namespace App\Http\Middleware;

use App\Support\AssociationWorkspace;
use App\Support\FilmunityReviewer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RedirectReviewerDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $request->query('panel') === 'applications') {
            return to_route('membership-applications.index');
        }

        if ($user === null || $request->query('view') === 'member' || $request->query->has('panel')) {
            return $next($request);
        }

        if ($request->session()->pull('filmunity.association-registration') && $user->association_signup) {
            return to_route('association-registration.index');
        }

        if (FilmunityReviewer::allows($user)) {
            return to_route('filmunity-admin.dashboard');
        }

        if (AssociationWorkspace::forAdmin($user)->exists()) {
            return to_route('association-workspaces.index');
        }

        if ($user->association_signup) {
            return to_route('association-registration.index');
        }

        return $next($request);
    }
}
