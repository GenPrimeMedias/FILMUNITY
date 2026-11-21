<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ]]);
    }

    public function test_google_can_create_a_verified_gmail_account(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());
        $this->fakeGoogle('google-new', 'filmunity-google-test@gmail.com');

        $this->requestGoogleCallback()->assertRedirect(route('dashboard'));

        $user = User::where('google_id', 'google-new')->firstOrFail();
        $this->assertSame('filmunity-google-test@gmail.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_signs_in_by_subject_after_an_email_change(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_id' => 'google-returning'])->save();
        $this->fakeGoogle('google-returning', 'filmunity-google-changed@gmail.com');

        $this->requestGoogleCallback()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('users', ['email' => 'filmunity-google-changed@gmail.com']);
    }

    public function test_google_does_not_sign_in_to_an_existing_password_account_by_email(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());
        $user = User::factory()->create(['email' => 'Filmunity-Google-Match@gmail.com']);
        $this->fakeGoogle('google-other', 'filmunity-google-match@gmail.com');

        $this->requestGoogleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_id' => null]);
    }

    public function test_google_rejects_an_unverified_email_at_sign_up(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());
        $this->fakeGoogle('google-unverified', 'filmunity-google-unverified@gmail.com', false);

        $this->requestGoogleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['google_id' => 'google-unverified']);
    }

    public function test_third_party_email_requires_filmunity_verification(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());
        Notification::fake();
        $this->fakeGoogle('google-external', 'filmunity-google-test@example.com');

        $this->requestGoogleCallback()->assertRedirect(route('dashboard'));

        $user = User::where('google_id', 'google-external')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_google_preserves_fortify_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->create();
        $user->forceFill([
            'google_id' => 'google-two-factor',
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->fakeGoogle('google-two-factor', $user->email);

        $this->requestGoogleCallback()->assertRedirect(route('two-factor.login'))->assertSessionHas('login.id', $user->id);

        $this->assertGuest();
    }

    public function test_google_rejects_invalid_oauth_state(): void
    {
        $provider = mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->once()->andThrow(InvalidStateException::class);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->requestGoogleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    private function requestGoogleCallback(): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('auth.google.callback', ['code' => 'test-code']));
    }

    private function fakeGoogle(string $subject, string $email, bool $verified = true): void
    {
        $profile = (new GoogleUser)->map(['id' => $subject, 'name' => 'Google Test User', 'email' => $email])
            ->setRaw(['email_verified' => $verified]);

        $provider = mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($profile);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
    }
}
