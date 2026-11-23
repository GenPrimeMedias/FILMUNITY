<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfessionalProfileController
{
    public function dashboard(Request $request): Response
    {
        $panel = $request->query('panel');
        $profiles = DB::table('professional_profile_drafts')->where('user_id', $request->user()->id);
        $draft = $panel === 'profile'
            ? (clone $profiles)->select('profile', 'wizard_step', 'username')
                ->selectRaw("public_profile->>'username' AS public_username")->first()
            : null;
        $summary = $panel !== 'profile' && $panel !== 'applications'
            ? $profiles->selectRaw("(profile - 'banner' - 'photo' - 'heroSlides') AS profile, CASE WHEN COALESCE(profile->>'photo', '') <> '' THEN 1 ELSE 0 END AS has_photo")->first()
            : null;

        return Inertia::render('dashboard', [
            'professionalProfileDraft' => $draft ? [
                'profile' => json_decode($draft->profile, true, flags: JSON_THROW_ON_ERROR),
                'step' => $draft->wizard_step,
            ] : null,
            'publishedProfileUrl' => $draft && $draft->username && $draft->public_username === $draft->username
                ? route('community.profile', ['username' => $draft->username], false)
                : null,
            'memberProfileSummary' => $summary ? [
                'profile' => json_decode($summary->profile, true, flags: JSON_THROW_ON_ERROR),
                'hasPhoto' => (bool) $summary->has_photo,
            ] : null,
        ]);
    }

    public function avatar(Request $request): HttpResponse
    {
        $photo = DB::table('professional_profile_drafts')
            ->where('user_id', $request->user()->id)
            ->selectRaw("profile->>'photo' AS photo")
            ->value('photo');

        abort_unless(is_string($photo) && preg_match(
            '~^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$~D', $photo, $parts,
        ), 404);

        $bytes = base64_decode($parts[2], true);
        abort_if($bytes === false, 404);

        return response($bytes, 200, [
            'Content-Type' => $parts[1],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private const USERNAME_PATTERN = '/\A[a-z0-9_]{3,30}\z/';

    private function normalizeUsername(string $username): string
    {
        $username = trim($username);

        return strtolower(str_starts_with($username, '@') ? substr($username, 1) : $username);
    }

    public function usernameAvailability(Request $request): JsonResponse
    {
        $input = $request->query('username');
        $username = is_string($input) ? $this->normalizeUsername($input) : '';

        if (! preg_match(self::USERNAME_PATTERN, $username)) {
            abort(422, 'Enter a username with 3–30 letters, numbers or underscores.');
        }

        $taken = DB::table('professional_profile_drafts')
            ->where('username', $username)
            ->where('user_id', '!=', $request->user()->id)
            ->exists();

        return response()->json(['available' => ! $taken])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): RedirectResponse
    {
        $textFields = [
            'name', 'industry', 'headline', 'specialisations',
            'languages', 'city', 'travel', 'availability', 'highlights', 'training',
            'memberships', 'featuredProjectId',
        ];

        $profileFields = [...$textFields, 'role', 'username', 'additional', 'photo', 'banner', 'heroSlides', 'bio', 'social', 'socialLinks',
            'portfolio', 'showWorkPreferences', 'openToEnquiries', 'showreel'];

        $input = $request->input('profile.username');
        if (is_string($input)) {
            $username = $this->normalizeUsername($input);
            $request->merge(['profile' => [
                ...$request->input('profile'),
                'username' => $username === '' ? null : $username,
            ]]);
        }

        $rules = [
            'step' => ['required', 'integer', 'between:0,6'],
            'profile' => ['required', 'array:'.implode(',', $profileFields)],
            'profile.role' => ['nullable', 'string', Rule::in(config('professional-profile-roles.allowed'))],
            'profile.username' => [
                'nullable',
                Rule::requiredIf(fn () => in_array($request->input('step'), [2, 3, 4, 5, 6, '2', '3', '4', '5', '6'], true)),
                'string',
                'regex:'.self::USERNAME_PATTERN,
                Rule::unique('professional_profile_drafts', 'username')->ignore($request->user()->id, 'user_id'),
            ],
            'profile.additional' => ['present', 'array', 'list', 'max:4'],
            'profile.additional.*' => ['required', 'string', 'max:80'],
            'profile.photo' => ['nullable', 'string', 'max:1398128', $this->validateProfileImage(...)],
            'profile.banner' => ['nullable', 'string', 'max:2796240', $this->validateProfileImage(...)],
            'profile.heroSlides' => ['array', 'list', 'max:10'],
            'profile.heroSlides.*' => ['bail', 'required', 'string', 'max:349560', $this->validateProfileImage(...)],
            'profile.socialLinks' => ['array:instagram,youtube,linkedin,imdb,facebook,x'],
            'profile.socialLinks.*' => ['nullable', 'url:https', 'max:2048'],
            'profile.bio' => ['nullable', 'string', 'max:10000'],
            'profile.social' => ['nullable', 'url:https', 'max:2048'],
            'profile.showreel' => ['nullable', 'url:https', 'max:2048'],
            'profile.showWorkPreferences' => ['required', 'boolean'],
            'profile.openToEnquiries' => ['required', 'boolean'],
            'profile.portfolio' => ['present', 'array', 'list', 'max:6'],
            'profile.portfolio.*' => ['required', 'array:id,title,role,type,year,url,contribution'],
            'profile.portfolio.*.url' => ['present', 'nullable', 'url:https', 'max:2048'],
        ];

        foreach ($textFields as $field) {
            $rules["profile.{$field}"] = ['nullable', 'string', 'max:2000'];
        }

        foreach (['id', 'title', 'role', 'type', 'year', 'contribution'] as $field) {
            $rules["profile.portfolio.*.{$field}"] = ['present', 'nullable', 'string', 'max:2000'];
        }

        $validated = $request->validate($rules, [
            'profile.username.unique' => 'That username is already taken. Choose another.',
            'profile.username.regex' => 'Use 3–30 lowercase letters, numbers or underscores.',
        ]);
        $profile = $validated['profile'];
        array_walk_recursive($profile, function (&$value): void {
            $value ??= '';
        });

        try {
            DB::transaction(function () use ($request, $profile, $validated): void {
                $userId = $request->user()->id;
                $username = ($profile['username'] ?? '') === '' ? null : $profile['username'];
                DB::table('professional_profile_drafts')->upsert([
                    'user_id' => $userId,
                    'username' => $username,
                    'profile' => json_encode($profile, JSON_THROW_ON_ERROR),
                    'wizard_step' => $validated['step'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], ['user_id'], ['username', 'profile', 'wizard_step', 'updated_at']);

                DB::table('professional_profile_drafts')->where('user_id', $userId)
                    ->whereNotNull('public_profile')
                    ->whereRaw("public_profile->>'username' IS DISTINCT FROM CAST(? AS text)", [$username])
                    ->update(['public_profile' => null]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'profile.username' => 'That username is already taken. Choose another.',
            ]);
        }

        return redirect()->route('dashboard', ['panel' => 'profile', 'edit' => 1], 303);
    }

    private function validateProfileImage(string $attribute, string $value, Closure $fail): void
    {
        $isBanner = $attribute === 'profile.banner';
        $isSlide = str_starts_with($attribute, 'profile.heroSlides.');

        if (! $isBanner && ! $isSlide && $value === 'sample-avatar') {
            return;
        }

        if (! preg_match('~^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$~D', $value, $parts)) {
            $fail('Choose a JPEG, PNG or WebP image.');

            return;
        }

        $limit = $isSlide ? 256 * 1024 : ($isBanner ? 2 : 1) * 1024 * 1024;
        $bytes = base64_decode($parts[2], true);
        $image = $bytes !== false && strlen($bytes) <= $limit
            ? @getimagesizefromstring($bytes)
            : false;

        if ($image === false || $image['mime'] !== $parts[1]) {
            $fail(match (true) {
                $isSlide => 'Choose a valid slider image up to 256 KB.',
                $isBanner => 'Choose a valid banner up to 2 MB.',
                default => 'Choose a valid profile photo up to 1 MB.',
            });

            return;
        }

        if ($isBanner && ($image[0] < 1200 || $image[0] > 6000 || $image[0] !== 3 * $image[1])) {
            $fail('Use a 3:1 banner between 1200 × 400 and 6000 × 2000 pixels.');
        }
    }
}
