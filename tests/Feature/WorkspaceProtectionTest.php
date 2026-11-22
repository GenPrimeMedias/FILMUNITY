<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\TestCase;

class WorkspaceProtectionTest extends TestCase
{
    use DatabaseTransactions, FinanceWorkflowFixtures;

    private function enroll(User $owner, string $id): string
    {
        $base = "/association-workspaces/$id/protection";
        $this->withCredentials();
        $this->actingAs($owner)->getJson($base.'?resource=workspace')->assertOk();
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->actingAs($owner)->postJson($base.'/confirm', ['password' => 'password'])->assertOk();
        $this->postJson($base.'/pin', ['pin' => '482617', 'pin_confirmation' => '482617'])->assertOk();
        $this->postJson($base.'/unlock', ['pin' => '482617'])->assertOk();

        return $base;
    }

    public function test_pin_requires_step_up_is_hashed_and_all_master_paths_require_unlock_and_lease(): void
    {
        [$owner, $id] = $this->association(true);
        $base = "/association-workspaces/$id/protection";
        $this->actingAs($owner)->postJson($base.'/pin', ['pin' => '482617', 'pin_confirmation' => '482617'])->assertForbidden();
        foreach (["/association-workspaces/$id/setup", "/association-finance/$id/account", "/association-finance/$id/masters/groups", "/association-finance/$id/masters/vendors", "/association-finance/$id/masters/sync", "/association-finance/$id/settings", "/association-finance/$id/tax/settings", "/association-finance/$id/tax/vendor-reviews", "/association-finance/$id/tally/mappings", "/association-workspaces/$id/mail"] as $url) {
            $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('protection');
        }
        $this->enroll($owner, $id);
        $hash = DB::table('association_workspace_protection')->where('association_id', $id)->value('pin_hash');
        $this->assertNotSame('482617', $hash);
        $this->assertTrue(Hash::check('482617', $hash));
        $this->getJson($base.'?resource=masters')->assertOk()->assertJsonMissing(['pin_hash' => $hash, 'pin' => '482617']);
        $this->postJson("/association-finance/$id/account", [])->assertUnprocessable()->assertJsonValidationErrors('protection');
        $this->actingAs(User::factory()->create())->getJson($base.'?resource=masters')->assertNotFound();
        $this->postJson($base.'/unlock', ['pin' => '482617'])->assertNotFound();
    }

    public function test_lease_contention_heartbeat_version_save_release_and_expiry(): void
    {
        [$owner, $id] = $this->association(true);
        $base = $this->enroll($owner, $id);
        $lease = $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'acquire', 'version' => 0])->assertOk()->json();
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'acquire', 'version' => 0])->assertConflict();
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'heartbeat', 'token' => 'forged'])->assertConflict();
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'heartbeat', 'token' => $lease['token']])->assertOk();
        $headers = ['X-Workspace-Lease' => $lease['token'], 'X-Workspace-Version' => (string) $lease['version']];
        $entry = ['code' => 'PROTECTED', 'name' => 'Protected test ledger', 'type' => 'expense', 'is_money' => false];
        $this->postJson("/association-finance/$id/account", $entry, [...$headers, 'X-Workspace-Version' => '999'])->assertUnprocessable()->assertJsonValidationErrors('protection');
        $this->postJson("/association-finance/$id/account", $entry, $headers)->assertRedirect();
        $this->assertDatabaseHas('association_finance_accounts', ['association_id' => $id, 'code' => 'PROTECTED']);
        $this->postJson("/association-finance/$id/account", [...$entry, 'code' => 'SECOND'], $headers)->assertUnprocessable()->assertJsonValidationErrors('protection');
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'acquire', 'version' => 0])->assertConflict();
        $next = $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'acquire', 'version' => 1])->assertOk()->json();
        $this->assertSame($lease['version'] + 1, $next['version']);
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'release', 'token' => $next['token']])->assertOk();
        $third = $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'acquire', 'version' => 1])->assertOk()->json();
        $this->travel(91)->seconds();
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'heartbeat', 'token' => $third['token']])->assertConflict();
    }

    public function test_pin_failure_lock_unlock_expiry_and_authenticated_reset_invalidate_old_grants(): void
    {
        [$owner, $id] = $this->association(true);
        $base = $this->enroll($owner, $id);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($base.'/unlock', ['pin' => '000000'])->assertUnprocessable();
        }
        $this->postJson($base.'/unlock', ['pin' => '482617'])->assertStatus(429);
        $this->travel(16)->minutes();
        $this->postJson($base.'/unlock', ['pin' => '482617'])->assertOk();
        $this->travel(11)->minutes();
        $this->getJson($base.'?resource=workspace')->assertJsonPath('unlocked', false);
        $this->postJson($base.'/pin', ['pin' => '135790', 'pin_confirmation' => '135790'])->assertForbidden();
        $this->postJson($base.'/confirm', ['password' => 'password'])->assertOk();
        $this->postJson($base.'/pin', ['pin' => '135790', 'pin_confirmation' => '135790'])->assertOk();
        $this->postJson($base.'/unlock', ['pin' => '482617'])->assertUnprocessable();
        $this->postJson($base.'/unlock', ['pin' => '135790'])->assertOk();
        $this->assertDatabaseHas('association_configuration_events', ['association_id' => $id, 'action' => 'pin_reset']);
    }

    public function test_another_session_cannot_reuse_an_unlock_or_lease_and_reset_revokes_the_original(): void
    {
        [$owner, $id] = $this->association(true);
        $base = $this->enroll($owner, $id);
        $lease = $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'acquire', 'version' => 0])->assertOk()->json();
        $oldSession = app('session')->getId();
        app('session')->migrate(false);
        app('session')->save();
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->getJson($base.'?resource=masters')->assertJsonPath('unlocked', false);
        $this->postJson($base.'/unlock', ['pin' => '482617'])->assertOk();
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'heartbeat', 'token' => $lease['token']])->assertConflict();
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'acquire', 'version' => 0])->assertConflict();
        $this->postJson($base.'/confirm', ['password' => 'password'])->assertOk();
        $this->postJson($base.'/pin', ['pin' => '135790', 'pin_confirmation' => '135790'])->assertOk();
        $this->withCookie(config('session.cookie'), $oldSession);
        $this->getJson($base.'?resource=masters')->assertJsonPath('unlocked', false);
        $this->postJson($base.'/lease', ['resource' => 'masters', 'operation' => 'heartbeat', 'token' => $lease['token']])->assertUnprocessable();
    }

    public function test_google_account_can_step_up_without_a_local_password_and_different_subject_is_rejected(): void
    {
        [$owner, $id] = $this->association();
        $owner->forceFill(['google_id' => 'SYNTHETIC-GOOGLE-ONE', 'password' => Hash::make(Str::random(64))])->save();
        config(['services.google.client_id' => 'synthetic-client', 'services.google.client_secret' => 'synthetic-secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $provider = \Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://example.test/google-reauth'));
        $profile = (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])->map(['id' => 'SYNTHETIC-GOOGLE-ONE', 'email' => $owner->email, 'name' => $owner->name]);
        $otherProfile = (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])->map(['id' => 'SYNTHETIC-GOOGLE-TWO', 'email' => 'other@example.test', 'name' => 'Other synthetic account']);
        $provider->shouldReceive('user')->andReturn($otherProfile, $profile);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        $this->withCredentials()->actingAs($owner)->getJson("/association-workspaces/$id/protection?resource=workspace")->assertOk();
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->get('/workspace-security/google/redirect')->assertRedirect('https://example.test/google-reauth');
        $this->get('/workspace-security/google/callback?code=synthetic')->assertRedirect('/association-workspaces')->assertSessionHasErrors('protection');
        $this->postJson("/association-workspaces/$id/protection/pin", ['pin' => '482617', 'pin_confirmation' => '482617'])->assertForbidden();
        $this->get('/workspace-security/google/redirect')->assertRedirect();
        $this->get('/workspace-security/google/callback?code=synthetic')->assertRedirect('/association-workspaces');
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->postJson("/association-workspaces/$id/protection/pin", ['pin' => '482617', 'pin_confirmation' => '482617'])->assertOk();
        $this->assertAuthenticatedAs($owner);
    }
}
