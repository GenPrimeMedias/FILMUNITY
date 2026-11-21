<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicProfessionalProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profile_is_private_until_published_and_can_be_unpublished(): void
    {
        $user = User::factory()->create(['email' => 'private-member@example.test']);
        $url = '/community/@filmunity_member';
        $this->get($url)->assertNotFound();
        $this->post('/professional-profile/publication')->assertRedirect(route('login'));

        $draft = [
            'step' => 6,
            'profile' => [
                'username' => 'filmunity_member',
                'name' => 'FILMUNITY Member',
                'industry' => 'Tamil',
                'role' => 'VFX & CGI (Visual Effects)',
                'bio' => 'Published work bio',
                'photo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZV0AAAAASUVORK5CYII=',
                'additional' => [],
                'portfolio' => [],
                'city' => 'Private City',
                'showWorkPreferences' => false,
                'openToEnquiries' => true,
                'socialLinks' => ['instagram' => 'https://www.instagram.com/example/'],
            ],
        ];
        $draft['profile']['heroSlides'] = array_fill(0, 11, $draft['profile']['photo']);
        $this->actingAs($user)->putJson('/professional-profile/draft', $draft)
            ->assertUnprocessable()->assertJsonValidationErrors('profile.heroSlides');

        $draft['profile']['heroSlides'] = array_fill(0, 2, $draft['profile']['photo']);
        $this->put('/professional-profile/draft', $draft)->assertStatus(303);
        $this->get($url)->assertNotFound();
        $this->post('/professional-profile/publication')->assertStatus(303);

        $this->app['auth']->forgetGuards();
        $this->assertGuest();
        $this->get($url)->assertOk()
            ->assertSee('FILMUNITY Member')
            ->assertSee('Published work bio')
            ->assertSee('VFX & CGI (Visual Effects)')
            ->assertSee('data-slide-next', false)
            ->assertSee('id="services"', false)
            ->assertSee('id="contact"', false)
            ->assertSee('https://www.instagram.com/example/')
            ->assertDontSee('Private City')
            ->assertDontSee($user->email);

        $draft['profile']['bio'] = 'New private draft bio';
        $draft['profile']['role'] = 'Director';
        $this->actingAs($user)->put('/professional-profile/draft', $draft)->assertStatus(303);
        $this->get($url)->assertSee('Published work bio')->assertDontSee('New private draft bio');
        $this->post('/professional-profile/publication')->assertStatus(303);
        $this->get($url)->assertSee('New private draft bio')->assertSee('Director');
        $this->delete('/professional-profile/publication')->assertStatus(303);
        $this->get($url)->assertNotFound();
    }

    public function test_incomplete_profile_cannot_be_published(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/professional-profile/publication')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publication');
    }
}
