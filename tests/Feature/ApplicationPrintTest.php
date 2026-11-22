<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\FilmunityBrandAssets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\TestCase;

class ApplicationPrintTest extends TestCase
{
    use DatabaseTransactions, FinanceWorkflowFixtures;

    public function test_membership_print_uses_submission_snapshot_and_checks_all_access_roles(): void
    {
        [$owner, $association] = $this->association();
        $member = User::factory()->create();
        $id = (string) Str::uuid();
        $snapshot = ['name' => 'Original Tamil member மீனா', 'email' => 'synthetic@example.test', 'answers' => [['id' => (string) Str::uuid(), 'label' => 'Original question', 'value' => 'Saved answer'], ['id' => (string) Str::uuid(), 'label' => 'Original evidence', 'value' => 'Document uploaded', 'file_path' => 'private/DO-NOT-PRINT-TOKEN/file.pdf', 'file_name' => 'synthetic-evidence.pdf']]];
        DB::table('association_membership_applications')->insert(['id' => $id, 'association_id' => $association, 'member_id' => $member->id, 'statement' => 'Original statement', 'form_snapshot' => json_encode($snapshot), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_workspace_settings')->where('association_id', $association)->update(['fields' => json_encode([['label' => 'Changed current question']])]);
        $url = "/membership-applications/$id/print";
        $this->get($url)->assertRedirect(route('login'));
        $response = $this->actingAs($member)->get($url)->assertOk()->assertSee('Original question')->assertSee('Saved answer')->assertSee('synthetic-evidence.pdf')->assertSee('Original Tamil member மீனா')->assertDontSee('Changed current question')->assertDontSee('DO-NOT-PRINT-TOKEN')->assertSee('Print / Save as PDF')->assertSee('Applicant signature');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->actingAs($owner)->get($url)->assertOk();
        [$otherOwner] = $this->association();
        $this->actingAs($otherOwner)->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $reviewer = User::factory()->create();
        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);
        $this->actingAs($reviewer)->get($url)->assertOk();
        $this->assertDatabaseHas('association_membership_applications', ['id' => $id, 'status' => 'pending', 'reviewed_at' => null]);
    }

    public function test_registration_print_is_private_authorized_and_read_only(): void
    {
        [$owner, $id] = $this->association();
        $before = DB::table('association_registration_applications')->where('id', $id)->first();
        $this->actingAs($owner)->get("/association-registration/$id/print")->assertOk()->assertSee($before->name)->assertSee('Applicant account reference')->assertSee('Not retained separately on this registration')->assertSee('Offline office use only')->assertSee('FILMUNITY')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->actingAs(User::factory()->create())->get("/association-registration/$id/print")->assertNotFound();
        $reviewer = User::factory()->create();
        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);
        $this->actingAs($reviewer)->get("/association-registration/$id/print")->assertOk();
        $this->assertEquals($before, DB::table('association_registration_applications')->where('id', $id)->first());
    }

    public function test_static_logo_validation_and_preferred_asset_fallbacks(): void
    {
        $safe = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 180 40"><path d="M0 0L10 10"/></svg>';
        $this->assertTrue(FilmunityBrandAssets::safeSvg($safe));
        foreach (['<svg><script>alert(1)</script></svg>', '<svg onload="alert(1)"/>', '<svg><use href="https://example.test/external.svg#logo"/></svg>', '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///private">]><svg>&x;</svg>'] as $unsafe) {
            $this->assertFalse(FilmunityBrandAssets::safeSvg($unsafe));
        }
        $original = public_path();
        $temporary = storage_path('framework/testing/brand-'.Str::uuid());
        mkdir($temporary.'/brand', 0755, true);
        app()->usePublicPath($temporary);
        try {
            file_put_contents($temporary.'/brand/filmunity-logo-full.svg', $safe);
            file_put_contents($temporary.'/brand/filmunity-logo-mini.svg', '<svg><script/></svg>');
            file_put_contents($temporary.'/brand/filmunity-logo-mini.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5xkAAAAASUVORK5CYII='));
            $paths = FilmunityBrandAssets::paths();
            $this->assertStringStartsWith('/brand/filmunity-logo-full.svg?v=', $paths['full']);
            $this->assertStringStartsWith('/brand/filmunity-logo-mini.png?v=', $paths['mini']);
        } finally {
            app()->usePublicPath($original);
        }
    }
}
