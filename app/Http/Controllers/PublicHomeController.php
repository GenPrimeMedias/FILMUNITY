<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PublicHomeController
{
    private const ROLES = [
        'Cinematographer', 'Director', 'Film Editor', 'Sound Designer', 'Writer',
        'Actor', 'Art Director', 'Musician', 'Production Staff',
    ];

    private const LANGUAGES = ['Tamil', 'Malayalam', 'Telugu', 'Kannada', 'English'];

    public function index(Request $request): Response
    {
        $filters = [
            'q' => is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : '',
            'role' => is_string($request->query('role')) && in_array($request->query('role'), self::ROLES, true) ? $request->query('role') : '',
            'language' => is_string($request->query('language')) && in_array($request->query('language'), self::LANGUAGES, true) ? $request->query('language') : '',
            'available' => $request->boolean('available'),
        ];

        $talentQuery = DB::table('professional_profile_drafts')
            ->whereNotNull('public_profile')
            ->whereRaw("public_profile->>'username' = username")
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
                $query->where(function ($nested) use ($term): void {
                    foreach (['name', 'username', 'role', 'headline', 'highlights', 'portfolio'] as $field) {
                        $nested->orWhereRaw("public_profile->>? ILIKE ?", [$field, $term]);
                    }
                });
            })
            ->when($filters['role'] !== '', fn ($query) => $query->whereRaw("public_profile->>'role' = ?", [$filters['role']]))
            ->when($filters['language'] !== '', fn ($query) => $query->whereRaw("public_profile->>'languages' ILIKE ?", ['%'.$filters['language'].'%']))
            ->when($filters['available'], fn ($query) => $query->where(function ($nested): void {
                $nested->whereRaw("lower(coalesce(public_profile->>'availability', '')) LIKE '%available%'")
                    ->orWhereRaw("public_profile->>'openToEnquiries' = 'true'");
            }))
            ->orderByRaw("lower(public_profile->>'name')");

        $totalTalentsCount = (clone $talentQuery)->count();
        $talents = $talentQuery
            ->paginate(12)
            ->withQueryString()
            ->through(fn ($row) => $this->talent($row));

        $associations = DB::table('association_registration_applications as associations')
            ->leftJoin('association_workspace_settings as settings', 'settings.association_id', '=', 'associations.id')
            ->leftJoinSub(
                DB::table('association_membership_plans')
                    ->where('active', true)
                    ->selectRaw('association_id, count(*) as active_plan_count')
                    ->groupBy('association_id'),
                'plans',
                'plans.association_id',
                '=',
                'associations.id',
            )
            ->where('associations.status', 'approved')
            ->orderByRaw('lower(coalesce(settings.display_name, associations.name))')
            ->limit(12)
            ->get([
                'associations.id',
                'associations.name',
                'associations.city',
                'associations.industry',
                'settings.display_name',
                'settings.description',
                DB::raw('coalesce(plans.active_plan_count, 0) as active_plan_count'),
            ])
            ->map(fn ($association) => [
                'id' => $association->id,
                'name' => $association->display_name ?: $association->name,
                'description' => $association->description,
                'city' => $association->city,
                'industry' => $association->industry,
                'active_plan_count' => (int) $association->active_plan_count,
            ])
            ->values();

        return Inertia::render('welcome', [
            'talents' => $talents,
            'associations' => $associations,
            'filters' => $filters,
            'roles' => self::ROLES,
            'languages' => self::LANGUAGES,
            'totalTalentsCount' => $totalTalentsCount,
        ]);
    }

    private function talent(object $row): array
    {
        $profile = json_decode($row->public_profile, true, flags: JSON_THROW_ON_ERROR);
        $portfolio = is_array($profile['portfolio'] ?? null) ? $profile['portfolio'] : [];
        $featured = collect($portfolio)->firstWhere('featured', true) ?: ($portfolio[0] ?? null);

        return [
            'name' => $profile['name'] ?? 'Film professional',
            'username' => $row->username,
            'photo' => $profile['photo'] ?? null,
            'role' => $profile['role'] ?? 'Film professional',
            'language' => $this->firstLanguage($profile['languages'] ?? null),
            'city' => $profile['city'] ?? null,
            'bio' => $profile['bio'] ?? $profile['headline'] ?? null,
            'featured_work' => is_array($featured) ? ($featured['title'] ?? null) : null,
            'available' => (bool) ($profile['openToEnquiries'] ?? false)
                || str_contains(strtolower((string) ($profile['availability'] ?? '')), 'available'),
        ];
    }

    private function firstLanguage(mixed $languages): ?string
    {
        if (is_array($languages)) {
            return $languages[0] ?? null;
        }

        if (is_string($languages) && trim($languages) !== '') {
            return trim(preg_split('/[,|]/', $languages)[0]);
        }

        return null;
    }
}
