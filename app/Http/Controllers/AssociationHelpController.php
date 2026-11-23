<?php

namespace App\Http\Controllers;

use App\Support\AssociationWorkspace;
use App\Support\MembershipEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * @phpstan-type HelpField array{label: string, purpose: string, required: string, example: string, validation: string}
 * @phpstan-type HelpLink array{section: string, topic: string, label: string}
 * @phpstan-type HelpArticle array{id: string, title: string, why: string, before: list<string>, steps: list<string>, result: string, example: string, avoid: list<string>, table: list<list<string>>, terms: list<array{string, string}>, fields?: list<HelpField>, related?: list<HelpLink>}
 * @phpstan-type HelpSection array{id: string, title: string, summary: string, articles: list<HelpArticle>}
 * @phpstan-type HelpCatalog array{version: string, sections: list<HelpSection>, source: array{label: string, url: string, note: string}}
 */
final class AssociationHelpController extends Controller
{
    /** @return array{HelpCatalog, bool} */
    private function context(Request $request): array
    {
        $admin = AssociationWorkspace::forAdmin($request->user())->exists();
        abort_unless($admin || MembershipEligibility::representative($request->user()), 403);

        /** @var HelpCatalog $catalog */
        $catalog = json_decode((string) file_get_contents(resource_path('help/association-admin.json')), true, flags: JSON_THROW_ON_ERROR);
        if (! $admin) {
            $catalog['sections'] = array_values(array_filter($catalog['sections'], fn ($section) => $section['id'] === 'profile'));
        }

        return [$catalog, $admin];
    }

    /**
     * @param  HelpCatalog  $catalog
     * @return HelpSection
     */
    private function section(array $catalog, string $id): array
    {
        foreach ($catalog['sections'] as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
        }

        abort(404);
    }

    public function index(Request $request, ?string $section = null): InertiaResponse
    {
        [$catalog, $admin] = $this->context($request);
        $section ??= $admin ? 'association' : 'profile';
        $selected = $this->section($catalog, $section);
        $data = $request->validate(['topic' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/D']]);
        $topic = $data['topic'] ?? $selected['articles'][0]['id'];
        abort_unless(in_array($topic, array_column($selected['articles'], 'id'), true), 404);

        return Inertia::render('association-help', [
            'catalog' => $catalog, 'sectionId' => $section, 'topicId' => $topic, 'canManageAssociation' => $admin,
        ]);
    }

    /** @param list<string> $values */
    private function markdownRow(array $values): string
    {
        return '| '.implode(' | ', array_map(
            fn (string $value): string => str_replace(['|', "\r", "\n"], ['/', '', ' '], $value),
            $values,
        ))." |\n";
    }

    public function download(Request $request, string $section): Response
    {
        [$catalog] = $this->context($request);
        $selected = $this->section($catalog, $section);
        $text = '# FILMUNITY — '.$selected['title']." help\n\nUpdated ".$catalog['version']."\n\n".$selected['summary']."\n";

        foreach ($selected['articles'] as $article) {
            $text .= "\n## ".$article['title']."\n\n".$article['why']."\n\n### Before you begin\n\n";
            foreach ($article['before'] as $line) {
                $text .= '- '.$line."\n";
            }
            if (! empty($article['fields'])) {
                $text .= "\n### Fields in screen order\n\n| Field | Purpose | Required? | Synthetic example | Format and validation |\n|---|---|---|---|---|\n";
                foreach ($article['fields'] as $field) {
                    $text .= $this->markdownRow([$field['label'], $field['purpose'], $field['required'], $field['example'], $field['validation']]);
                }
            }
            $text .= "\n### Steps\n\n";
            foreach ($article['steps'] as $index => $line) {
                $text .= ($index + 1).'. '.$line."\n";
            }
            $text .= "\n**Expected result:** ".$article['result']."\n";
            if ($article['example'] !== '') {
                $text .= "\n**Example:** ".$article['example']."\n";
            }
            if ($article['table']) {
                $text .= "\n| Event | Debit | Credit |\n|---|---|---|\n";
                foreach ($article['table'] as $row) {
                    $text .= $this->markdownRow($row);
                }
            }
            if ($article['terms']) {
                $text .= "\n### Plain-language terms\n\n";
                foreach ($article['terms'] as [$term, $meaning]) {
                    $text .= '- **'.$term.'**: '.$meaning."\n";
                }
            }
            if ($article['avoid']) {
                $text .= "\n### Common mistakes and limits\n\n";
                foreach ($article['avoid'] as $line) {
                    $text .= '- '.$line."\n";
                }
            }
            if (! empty($article['related'])) {
                $text .= "\n### Related tasks\n\n";
                foreach ($article['related'] as $link) {
                    $text .= '- ['.$link['label'].']('.url('/association-help/'.$link['section']).'?topic='.$link['topic'].")\n";
                }
            }
        }

        if ($section === 'finance') {
            $text .= "\n## Accounting reference\n\n".$catalog['source']['label'].': '.$catalog['source']['url']."\n\n".$catalog['source']['note']."\n";
        }

        return response($text, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="FILMUNITY-'.$section.'-help.md"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
