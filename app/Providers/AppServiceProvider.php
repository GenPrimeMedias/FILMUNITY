<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Inertia::share('professionalProfileAvatar', function (): ?string {
            $user = request()->user();
            if (! $user) {
                return null;
            }


            $photoVersion = DB::table('professional_profile_drafts')
                ->where('user_id', $user->id)
                ->whereRaw("profile->>'photo' LIKE 'data:image/%'")
                ->selectRaw("md5(profile->>'photo') AS photo_version")
                ->value('photo_version');

            return $photoVersion
                ? route('professional-profile.avatar', ['v' => $photoVersion], false)
                : null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
