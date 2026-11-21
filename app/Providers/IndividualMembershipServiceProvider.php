<?php
namespace App\Providers;

use App\Support\IndividualMembership;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

final class IndividualMembershipServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Verified::class, fn ($event) => IndividualMembership::ensure($event->user));
        Event::listen(Registered::class, function ($event): void {
            if ($event->user->email_verified_at !== null) IndividualMembership::ensure($event->user);
        });
        Inertia::share('filmunityMembership', function (): ?array {
            $user = request()->user();
            return $user !== null && $user->email_verified_at !== null ? IndividualMembership::summary($user) : null;
        });
    }
}
