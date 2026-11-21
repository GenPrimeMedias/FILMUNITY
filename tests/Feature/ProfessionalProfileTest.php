<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfessionalProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_home_redirects_to_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_guest_cannot_save_a_draft(): void
    {
        $this->put('/professional-profile/draft')->assertRedirect(route('login'));
    }

    public function test_draft_resumes_for_its_owner_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $dashboard = route('dashboard', ['panel' => 'profile']);
        $draft = [
            'step' => 1,
            'profile' => [
                'name' => 'Profile draft',
                'industry' => 'Tamil',
                'additional' => [],
                'portfolio' => [],
                'showWorkPreferences' => false,
                'openToEnquiries' => false,
            ],
        ];

        $this->actingAs($owner)->put('/professional-profile/draft', $draft)
            ->assertStatus(303)->assertRedirect(route('dashboard', ['panel' => 'profile', 'edit' => 1]));

        $this->actingAs($other)->get($dashboard)
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('professionalProfileDraft', null));

        $this->actingAs($owner)->get($dashboard)
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('professionalProfileDraft.step', 1)
                ->where('professionalProfileDraft.profile.name', 'Profile draft'));
    }

    public function test_saving_an_edit_updates_the_existing_profile(): void
    {
        $user = User::factory()->create();
        $draft = [
            'step' => 1,
            'profile' => [
                'name' => 'Original name',
                'username' => 'profile_editor',
                'additional' => [],
                'portfolio' => [],
                'showWorkPreferences' => false,
                'openToEnquiries' => false,
            ],
        ];

        $this->actingAs($user)->put('/professional-profile/draft', $draft)->assertStatus(303);
        $draft['profile']['name'] = 'Updated name';
        $this->put('/professional-profile/draft', $draft)->assertStatus(303);

        $this->get(route('dashboard', ['panel' => 'profile']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('professionalProfileDraft.profile.name', 'Updated name')
                ->where('professionalProfileDraft.profile.username', 'profile_editor'));
    }

    public function test_username_is_normalized_and_reserved_for_one_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $url = route('professional-profile.username-availability', ['username' => '@FILM_MEMBER']);
        $draft = [
            'step' => 2,
            'profile' => [
                'name' => 'Member',
                'username' => '@FILM_MEMBER',
                'additional' => [],
                'portfolio' => [],
                'showWorkPreferences' => false,
                'openToEnquiries' => false,
            ],
        ];

        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('available', true);
        $this->put('/professional-profile/draft', $draft)->assertStatus(303);
        $this->assertDatabaseHas('professional_profile_drafts', [
            'user_id' => $owner->id,
            'username' => 'film_member',
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('available', true);
        $this->put('/professional-profile/draft', $draft)->assertStatus(303);

        $this->actingAs($other)->getJson($url)->assertOk()->assertJsonPath('available', false);
        $this->putJson('/professional-profile/draft', $draft)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile.username');
        $this->assertDatabaseMissing('professional_profile_drafts', ['user_id' => $other->id]);
    }

    public function test_banner_dimensions_and_social_links_are_validated_and_saved(): void
    {
        $user = User::factory()->create();
        $draft = [
            'step' => 0,
            'profile' => [
                'additional' => [],
                'portfolio' => [],
                'showWorkPreferences' => false,
                'openToEnquiries' => false,
                'banner' => $this->bannerPng(1200, 500),
                'socialLinks' => ['instagram' => 'http://www.instagram.com/example/'],
            ],
        ];

        $this->actingAs($user)->putJson('/professional-profile/draft', $draft)
            ->assertUnprocessable()->assertJsonValidationErrors(['profile.banner', 'profile.socialLinks.instagram']);

        $draft['profile']['banner'] = $this->bannerPng(1200, 400);
        $draft['profile']['socialLinks']['instagram'] = 'https://www.instagram.com/example/';
        $this->put('/professional-profile/draft', $draft)->assertStatus(303);

        $this->get(route('dashboard', ['panel' => 'profile']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('professionalProfileDraft.profile.banner', $draft['profile']['banner'])
                ->where('professionalProfileDraft.profile.socialLinks.instagram', 'https://www.instagram.com/example/'));
    }

    public function test_dashboard_summary_and_avatar_use_the_signed_in_members_profile(): void
    {
        $avatarUrl = route('professional-profile.avatar');
        $this->get($avatarUrl)->assertRedirect(route('login'));

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $photo = $this->bannerPng(32, 32);
        $draft = [
            'step' => 0,
            'profile' => [
                'name' => 'Dashboard member',
                'photo' => $photo,
                'additional' => [],
                'portfolio' => [],
                'showWorkPreferences' => false,
                'openToEnquiries' => false,
            ],
        ];

        $this->actingAs($owner)->put('/professional-profile/draft', $draft)->assertStatus(303);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('memberProfileSummary.profile.name', 'Dashboard member')
            ->where('memberProfileSummary.hasPhoto', true)
            ->missing('memberProfileSummary.profile.photo')
            ->where('professionalProfileAvatar', route('professional-profile.avatar', ['v' => md5($photo)], false)));

        $avatar = $this->get($avatarUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(base64_decode(explode(',', $photo, 2)[1], true), $avatar->getContent());

        $this->actingAs($other)->get($avatarUrl)->assertNotFound();
    }

    private function bannerPng(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            .$type.$data.pack('N', crc32($type.$data));

        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat(str_repeat("\0", $width + 1), $height)))
            .$chunk('IEND', '');

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
