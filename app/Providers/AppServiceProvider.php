<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
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
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
        $this->configureDefaults();
        foreach (['workspace-confirm' => 5, 'workspace-pin' => 5, 'workspace-unlock' => 6, 'workspace-lease' => 120, 'workspace-google' => 5, 'workspace-mail-save' => 10, 'workspace-mail-test' => 3, 'workspace-mail-review' => 10] as $name => $limit) {
            \Illuminate\Support\Facades\RateLimiter::for($name, fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute($limit)->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.($request->route('workspace') ?? 'account')));
        }

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
