<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PublicTalentDirectoryController
{
    private const ROLES = ['Cinematographer', 'Director', 'Film Editor', 'Sound Designer', 'Writer', 'Actor', 'Art Director', 'Musician', 'Production Staff'];
    private const LANGUAGES = ['Tamil', 'Malayalam', 'Telugu', 'Kannada', 'English'];

    public function index(Request $request): Response
    {
        $filters = [
            'q' => is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : '',
            'role' => $this->allowed($request->query('role'), self::ROLES),
            'language' => $this->allowed($request->query('language'), self::LANGUAGES),
            'city' => is_string($request->query('city')) ? mb_substr(trim($request->query('city')), 0, 80) : '',
            'available' => $request->boolean('available'),
            'sort' => in_array($request->query('sort'), ['latest', 'credits'], true) ? $request->query('sort') : 'latest',
        ];

        $query = DB::table('professional_profile_drafts')
            ->whereNotNull('public_profile')
            ->whereRaw("public_profile->>'username' = username")
            ->when($filters['q'] !== '', function ($builder) use ($filters): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
                $builder->where(function ($nested) use ($term): void {
                    foreach (['name', 'username', 'role', 'headline', 'highlights', 'portfolio'] as $field) {
                        $nested->orWhereRaw("public_profile->>? ILIKE ?", [$field, $term]);
                    }
                });
            })
            ->when($filters['role'] !== '', fn ($builder) => $builder->whereRaw("public_profile->>'role' = ?", [$filters['role']]))
            ->when($filters['language'] !== '', fn ($builder) => $builder->whereRaw("public_profile->>'languages' ILIKE ?", ['%'.$filters['language'].'%']))
            ->when($filters['city'] !== '', fn ($builder) => $builder->whereRaw("public_profile->>'city' ILIKE ?", ['%'.$filters['city'].'%']))
            ->when($filters['available'], fn ($builder) => $builder->where(function ($nested): void {
                $nested->whereRaw("lower(coalesce(public_profile->>'availability', '')) LIKE '%available%'")
                    ->orWhereRaw("public_profile->>'openToEnquiries' = 'true'");
            }))
            ->when($filters['sort'] === 'credits', fn ($builder) => $builder->orderByRaw("jsonb_array_length(coalesce(public_profile->'portfolio', '[]'::jsonb)) DESC"), fn ($builder) => $builder->orderByDesc('updated_at'))
            ->orderBy('username');

        return Inertia::render('community/talents', [
            'talents' => $query->paginate(18)->withQueryString()->through(fn ($row) => $this->talent($row)),
            'filters' => $filters,
            'roles' => self::ROLES,
            'languages' => self::LANGUAGES,
            'totalTalentsCount' => (clone $query)->count(),
        ]);
    }

    private function allowed(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    private function talent(object $row): array
    {
        $profile = json_decode($row->public_profile, true, flags: JSON_THROW_ON_ERROR);
        $portfolio = is_array($profile['portfolio'] ?? null) ? $profile['portfolio'] : [];
        $featured = collect($portfolio)->firstWhere('featured', true) ?: ($portfolio[0] ?? null);
        $languages = $profile['languages'] ?? null;

        return [
            'name' => $profile['name'] ?? 'Film professional',
            'username' => $row->username,
            'photo' => $profile['photo'] ?? null,
            'role' => $profile['role'] ?? 'Film professional',
            'language' => is_array($languages) ? ($languages[0] ?? null) : (is_string($languages) ? trim(preg_split('/[,|]/', $languages)[0]) : null),
            'city' => $profile['city'] ?? null,
            'bio' => $profile['bio'] ?? $profile['headline'] ?? null,
            'featured_work' => is_array($featured) ? ($featured['title'] ?? null) : null,
            'available' => (bool) ($profile['openToEnquiries'] ?? false) || str_contains(strtolower((string) ($profile['availability'] ?? '')), 'available'),
        ];
    }
}
