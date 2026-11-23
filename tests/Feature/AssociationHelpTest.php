<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class AssociationHelpTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_help_requires_verified_representative_or_admin_access(): void
    {
        $this->get('/association-help/finance')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/association-help/finance')->assertForbidden();
        $unverified = $this->representative('pending');
        $unverified->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($unverified)->get('/association-help/profile')->assertRedirect(route('verification.notice'));
        $user = $this->representative('approved');
        $this->actingAs($user)->get('/association-help/finance?topic=expense')->assertInertia(fn (Page $page) => $page->component('association-help')->where('topicId', 'expense')->where('sectionId', 'finance')->has('catalog.sections', 5));
    }

    public function test_pending_representative_has_profile_help_but_not_admin_guides(): void
    {
        $this->actingAs($this->representative('pending'))->get('/association-help')->assertInertia(fn (Page $page) => $page->where('sectionId', 'profile')->where('canManageAssociation', false)->has('catalog.sections', 1));
        $this->get('/association-help/finance')->assertNotFound();
        $this->get('/association-help/members/download')->assertNotFound();
    }

    public function test_unknown_topics_are_not_served_and_downloads_are_private(): void
    {
        $this->actingAs($this->representative('approved'))->get('/association-help/finance?topic=not-a-topic')->assertNotFound();
        $this->get('/association-help/not-a-section')->assertNotFound();
        $response = $this->get('/association-help/finance/download')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->assertSee('How to record an office expense')->assertSee('Save draft');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_all_help_articles_have_complete_how_to_structure(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('help/association-admin.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(5, $catalog['sections']);
        $count = 0;
        foreach ($catalog['sections'] as $section) {
            $ids = [];
            foreach ($section['articles'] as $a) {
                $this->assertNotContains($a['id'], $ids);
                $ids[] = $a['id'];
                $this->assertNotEmpty($a['title']);
                $this->assertNotEmpty($a['why']);
                $this->assertNotEmpty($a['before']);
                $this->assertGreaterThanOrEqual(3, count($a['steps']));
                $this->assertNotEmpty($a['result']);
                foreach ($a['table'] as $row) {
                    $this->assertCount(3, $row);
                }$count++;
            }
        }
        $this->assertGreaterThanOrEqual(24, $count, 'Keep the baseline guides while allowing additional validated help articles.');
    }

    public function test_every_article_and_related_link_is_available_to_an_authorized_owner(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('help/association-admin.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->actingAs($this->representative('approved'));
        $targets = [];
        foreach ($catalog['sections'] as $section) {
            foreach ($section['articles'] as $article) {
                $targets[$section['id'].'/'.$article['id']] = true;
            }
        }
        foreach ($catalog['sections'] as $section) {
            foreach ($section['articles'] as $article) {
                $this->assertNotEmpty($article['fields'], $article['id'].' needs a data-entry field reference.');
                foreach ($article['fields'] as $field) {
                    foreach (['label', 'purpose', 'required', 'example', 'validation'] as $key) {
                        $this->assertNotSame('', trim($field[$key]), $article['id'].'/'.$field['label'].'/'.$key);
                    }
                }
                foreach ($article['related'] as $link) {
                    $this->assertArrayHasKey($link['section'].'/'.$link['topic'], $targets);
                    if ($section['id'] === 'profile') {
                        $this->assertSame('profile', $link['section'], 'Pending representatives must not receive inaccessible management links.');
                    }
                }
                $this->get('/association-help/'.$section['id'].'?topic='.$article['id'])->assertOk()->assertInertia(fn (Page $page) => $page->where('sectionId', $section['id'])->where('topicId', $article['id']));
            }
            $download = $this->get('/association-help/'.$section['id'].'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            foreach ($section['articles'] as $article) {
                $download->assertSee($article['title'], false)->assertSee($article['fields'][0]['label'], false);
                foreach ($article['related'] as $link) {
                    $download->assertSee('/association-help/'.$link['section'].'?topic='.$link['topic'], false);
                }
            }
        }
    }

    public function test_pending_representative_download_contains_only_accessible_profile_help(): void
    {
        $this->actingAs($this->representative('pending'))->get('/association-help/profile/download')->assertOk()->assertSee('Fields in screen order')->assertDontSee('?topic=tds-challans', false)->assertDontSee('?topic=legacy-preview', false);
        foreach (['association', 'members', 'finance', 'board'] as $section) {
            $this->get('/association-help/'.$section)->assertNotFound();
            $this->get('/association-help/'.$section.'/download')->assertNotFound();
        }
        $this->actingAs(User::factory()->create())->get('/association-help/finance/download')->assertForbidden();
    }

    public function test_current_finance_and_legacy_limits_are_in_searchable_catalog_and_downloads(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('help/association-admin.json')), true, flags: JSON_THROW_ON_ERROR);
        $finance = collect($catalog['sections'])->firstWhere('id', 'finance');
        $members = collect($catalog['sections'])->firstWhere('id', 'members');
        $byId = collect($finance['articles'])->keyBy('id');
        foreach (['invoices-settlements', 'allocations', 'correction-notes', 'vendor-tds-reviews', 'vendor-certificates', 'vendor-history', 'vendor-threshold', 'tds-challans', 'print-documents', 'recurring-drafts', 'invoice-reminders', 'tally-export'] as $id) {
            $this->assertTrue($byId->has($id));
        }
        $this->assertStringContainsString('₹3,000.00', $byId['start']['example']);
        $this->assertStringContainsString('₹1,080.00', $byId['multi-line-billing']['example']);
        $this->assertStringContainsString('Live sending and reminder scheduling remain disabled', implode(' ', $byId['invoice-reminders']['before']));
        $this->assertStringContainsString('Accounts reported a successful TallyPrime 7.2 import with no errors', $byId['tally-export']['result']);
        $this->assertStringContainsString('Per-ledger reconciliation and repeat-import behavior remain pending', $byId['tally-export']['result']);
        $this->assertStringContainsString('not an independently witnessed import', $byId['tally-export']['result']);
        $this->assertStringNotContainsString('Native target import behavior remains unverified', json_encode($finance, JSON_UNESCAPED_UNICODE));
        $inventory = json_decode(file_get_contents(resource_path('help/workflow-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Accounts reported a successful TallyPrime 7.2 import with no errors', implode(' ', $inventory['limits']));
        $this->assertStringContainsString('Per-ledger reconciliation and repeat-import behavior remain pending', implode(' ', $inventory['limits']));
        $this->assertStringNotContainsString('native test-company import and duplicate handling remain unverified', implode(' ', $inventory['limits']));
        $this->assertStringContainsString('no import write action', json_encode($members, JSON_UNESCAPED_UNICODE));
        // The screen's existing JSON.stringify search includes these field references and limits.
        foreach (['Certificate valid until', 'Match amount (INR)', 'Source timezone', '000123', 'மீனா'] as $term) {
            $this->assertStringContainsString($term, json_encode($catalog, JSON_UNESCAPED_UNICODE));
        }
        $this->actingAs($this->representative('approved'))->get('/association-help/finance/download')->assertOk()->assertSee('₹3,000.00', false)->assertSee('Fields in screen order')->assertSee('Related tasks')->assertSee('/association-help/finance?topic=tds-challans', false);
    }

    public function test_workflow_inventory_references_existing_sources_and_help_topics(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('help/association-admin.json')), true, flags: JSON_THROW_ON_ERROR);
        $inventory = json_decode(file_get_contents(resource_path('help/workflow-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
        $topics = [];
        foreach ($catalog['sections'] as $section) {
            foreach ($section['articles'] as $article) {
                $topics[] = $section['id'].'/'.$article['id'];
            }
        }
        $this->assertCount(count($topics), $inventory['articles']);
        foreach ($inventory['articles'] as $article) {
            $this->assertContains($article['path'], array_map(fn ($topic) => '/association-help/'.str_replace('/', '?topic=', $topic), $topics));
        }
        foreach ($inventory['workflows'] as $workflow) {
            foreach ([$workflow['controller'], ...$workflow['screens'], ...$workflow['tests']] as $source) {
                $this->assertFileExists(base_path($source));
            }
            foreach ($workflow['articles'] as $topic) {
                $this->assertContains($topic, $topics);
            }
        }
        foreach ($inventory['commands'] as $command) {
            $this->assertFileExists(base_path($command['source']));
            $this->assertContains($command['article'], $topics);
        }
    }

    public function test_workspace_controls_and_application_print_guides_describe_current_limits(): void
    {
        $this->actingAs($this->representative('approved'));
        foreach (['association' => ['workspace-protection', 'email-configuration', 'logo-assets'], 'members' => ['application-printing'], 'profile' => ['application-printing'], 'finance' => ['tax-configuration-versions']] as $section => $topics) {
            foreach ($topics as $topic) {
                $this->get('/association-help/'.$section.'?topic='.$topic)->assertOk()->assertInertia(fn (Page $page) => $page->where('topicId', $topic));
            }
        }
        $this->get('/association-help/association/download')->assertOk()->assertSee('Existing associations must enroll')->assertSee('five minutes')->assertSee('Blank password retains')->assertSee('public/brand/filmunity-logo-mini.svg', false);
        $this->get('/association-help/finance/download')->assertOk()->assertSee('Server and database posting checks reject stale versions')->assertSee('Recurring member invoices remain ordinary non-tax drafts');
        $this->actingAs($this->representative('pending'))->get('/association-help/profile?topic=application-printing')->assertOk();
        $this->get('/association-help/association?topic=workspace-protection')->assertNotFound();
    }

    private function representative(string $status): User
    {
        $user = User::factory()->create(['association_signup' => true]);
        $id = (string) Str::uuid();
        DB::table('association_registration_applications')->insert(['id' => $id, 'applicant_id' => $user->id, 'name' => 'Help Test Association', 'name_key' => $id, 'organisation_type' => 'Association', 'industry' => 'Tamil', 'city' => 'Chennai', 'representative_role' => 'Secretary', 'status' => $status, 'official_contact_email' => $user->email, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }
}
