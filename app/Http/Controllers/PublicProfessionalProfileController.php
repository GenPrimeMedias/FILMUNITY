<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicProfessionalProfileController
{
    public function show(string $username): Response
    {
        $snapshot = DB::table('professional_profile_drafts')
            ->where('username', $username)
            ->whereRaw("public_profile->>'username' = username")
            ->value('public_profile');

        abort_unless(is_string($snapshot), 404);

        return response()->view('community-profile', [
            'profile' => json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function publish(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $drafts = DB::table('professional_profile_drafts')->where('user_id', $request->user()->id);
            $draft = (clone $drafts)->lockForUpdate()->first(['username', 'profile']);
            $profile = $draft ? json_decode($draft->profile, true, flags: JSON_THROW_ON_ERROR) : [];
            $photo = $this->image($profile['photo'] ?? null);

            if (! $draft?->username || $photo === null
                || trim($profile['name'] ?? '') === '' || trim($profile['bio'] ?? '') === ''
                || ! in_array($profile['industry'] ?? null, ['Tamil', 'Malayalam', 'Telugu', 'Kannada'], true)
                || ! in_array($profile['role'] ?? null, config('professional-profile-roles.allowed'), true)) {
                throw ValidationException::withMessages([
                    'publication' => 'Complete your name, username, industry, role, profile photo and work bio before publishing.',
                ]);
            }

            $snapshot = Arr::only($profile, [
                'name', 'headline', 'industry', 'additional', 'role', 'bio', 'specialisations',
                'languages', 'highlights', 'training', 'memberships', 'openToEnquiries',
            ]);

            if (! empty($profile['showWorkPreferences'])) {
                $snapshot += Arr::only($profile, ['city', 'travel', 'availability']);
            }

            $snapshot['username'] = $draft->username;
            $snapshot['photo'] = $photo;
            $snapshot['banner'] = $this->image($profile['banner'] ?? null);
            $snapshot['heroSlides'] = array_values(array_filter(array_map(
                $this->image(...),
                $profile['heroSlides'] ?? [],
            )));
            $snapshot['social'] = $this->link($profile['social'] ?? null);
            $snapshot['showreel'] = $this->link($profile['showreel'] ?? null);
            $snapshot['socialLinks'] = array_filter(array_map(
                $this->link(...),
                Arr::only($profile['socialLinks'] ?? [], ['instagram', 'youtube', 'linkedin', 'imdb', 'facebook', 'x']),
            ));

            $snapshot['portfolio'] = [];
            foreach ($profile['portfolio'] ?? [] as $project) {
                if (trim($project['title'] ?? '') === '' || trim($project['role'] ?? '') === '') {
                    throw ValidationException::withMessages([
                        'publication' => 'Add a title and credited role to each project or remove unfinished projects before publishing.',
                    ]);
                }

                $entry = Arr::only($project, ['title', 'role', 'type', 'year', 'contribution']);
                $entry['url'] = $this->link($project['url'] ?? null);
                $entry['featured'] = ! empty($profile['featuredProjectId'])
                    && $profile['featuredProjectId'] === ($project['id'] ?? null);
                $snapshot['portfolio'][] = $entry;
            }

            usort($snapshot['portfolio'], fn (array $left, array $right): int => $right['featured'] <=> $left['featured']);

            $drafts->update(['public_profile' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        });

        return redirect()->route('dashboard', ['panel' => 'profile'], 303);
    }

    public function unpublish(Request $request): RedirectResponse
    {
        DB::table('professional_profile_drafts')->where('user_id', $request->user()->id)
            ->update(['public_profile' => null]);

        return redirect()->route('dashboard', ['panel' => 'profile'], 303);
    }

    private function image(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$~D', $value)
            ? $value : null;
    }

    private function link(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($value);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            ? $value : null;
    }
}
