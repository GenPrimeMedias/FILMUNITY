<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\Response;

class GoogleAuthenticationController
{
    public function redirect(Request $request): Response
    {
        $this->ensureConfigured();

        if (! $request->boolean('association')) {
            $request->session()->forget('filmunity.association-registration');
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): LoginResponse|RedirectResponse
    {
        $this->ensureConfigured();

        if ($request->filled('error') || ! $request->filled('code')) {
            $request->session()->forget('state');

            return $this->failed();
        }

        try {
            $driver = Socialite::driver('google');
            if ($request->routeIs('workspace-google.callback')) {
                abort_unless($driver instanceof GoogleProvider, 503);
                $driver->redirectUrl(route('workspace-google.callback'));
            }
            $profile = $driver->user();
        } catch (InvalidStateException|GuzzleException) {
            return $this->failed();
        }

        $subject = $this->validatedSubject($profile->getId());

        if ($request->routeIs('workspace-google.callback')) {
            $reauth = $request->session()->pull('workspace.google_reauth');
            if (! is_array($reauth) || ! $request->user() || $request->user()->id !== $reauth['user'] || ($reauth['expires'] ?? 0) < now()->timestamp || $subject === null || ! hash_equals($reauth['subject'], $subject)) {
                return redirect('/association-workspaces')->withErrors(['protection' => 'Google reauthentication did not match the signed-in linked account.']);
            }
            $request->session()->regenerate();
            $request->session()->put('workspace.step_up', ['expires' => now()->addMinutes(5)->timestamp, 'user' => $request->user()->id, 'session' => hash('sha256', $request->session()->getId())]);

            return redirect('/association-workspaces')->with('status', 'Google account confirmed. Open Workspace setup to create or reset your PIN within five minutes.');
        }

        if ($subject === null || ! $profile instanceof AbstractUser) {
            return $this->failed();
        }

        $user = User::where('google_id', $subject)->first();

        if ($user === null) {
            $email = Str::lower(trim((string) $profile->getEmail()));
            $claims = $profile->getRaw();
            $verified = in_array($claims['email_verified'] ?? null, [true, 'true'], true);

            if (! Features::enabled(Features::registration()) || ! $verified
                || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->failed();
            }

            if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
                return $this->failed();
            }

            $name = trim((string) $profile->getName());
            $googleHostsEmail = Str::endsWith($email, '@gmail.com') || filled($claims['hd'] ?? null);

            try {
                $user = DB::transaction(fn () => User::forceCreate([
                    'name' => Str::substr($name !== '' ? $name : Str::before($email, '@'), 0, 255),
                    'email' => $email,
                    'email_verified_at' => $googleHostsEmail ? now() : null,
                    'password' => Str::random(64),
                    'google_id' => $subject,
                ]));
            } catch (UniqueConstraintViolationException) {
                $user = User::where('google_id', $subject)->first();

                if ($user === null) {
                    return $this->failed();
                }
            }

            if ($user->wasRecentlyCreated) {
                event(new Registered($user));
            }
        }

        $request->session()->forget(['login.id', 'login.remember']);

        if (Features::enabled(Features::twoFactorAuthentication()) && $user->two_factor_secret
            && (! Fortify::confirmsTwoFactorAuthentication() || $user->two_factor_confirmed_at !== null)) {
            $request->session()->regenerate();
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);
            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::guard(config('fortify.guard'))->login($user);
        $request->session()->regenerate();

        return app(LoginResponse::class);
    }

    private function ensureConfigured(): void
    {
        abort_unless(
            filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret'))
                && filled(config('services.google.redirect')),
            404,
        );
    }

    public function workspaceReauthentication(Request $request): Response
    {
        $this->ensureConfigured();
        abort_unless(filled($request->user()?->google_id), 403);
        $request->session()->put('workspace.google_reauth', ['user' => $request->user()->id, 'subject' => $request->user()->google_id, 'expires' => now()->addMinutes(5)->timestamp]);

        $driver = Socialite::driver('google');
        abort_unless($driver instanceof GoogleProvider, 503);

        return $driver->redirectUrl(route('workspace-google.callback'))->with(['prompt' => 'select_account'])->redirect();
    }

    private function validatedSubject(mixed $subject): ?string
    {
        return is_string($subject) && $subject !== '' && strlen($subject) <= 255 ? $subject : null;
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'email' => 'Google sign-in could not be completed. Try again or sign in with your existing email and password.',
        ]);
    }
}
