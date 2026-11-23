import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { filterHelpArticles } from '../../resources/js/lib/help-search.ts';

const catalog = JSON.parse(
    readFileSync(
        new URL('../../resources/help/association-admin.json', import.meta.url),
        'utf8',
    ),
);
const finance = catalog.sections.find(
    (section) => section.id === 'finance',
).articles;
const members = catalog.sections.find(
    (section) => section.id === 'members',
).articles;

test('blank search preserves section order and all articles', () => {
    assert.deepEqual(filterHelpArticles(finance, '  '), finance);
});

test('search finds field guidance regardless of case and surrounding spaces', () => {
    assert.ok(
        filterHelpArticles(finance, '  CERTIFICATE VALID UNTIL  ').some(
            (article) => article.id === 'vendor-certificates',
        ),
    );
    assert.ok(
        filterHelpArticles(finance, 'Match amount (INR)').some(
            (article) => article.id === 'tds-challans',
        ),
    );
});

test('Tamil names, leading zero numbers and preview limits remain searchable', () => {
    for (const query of [
        'மீனா',
        '000123',
        'Source timezone',
        'no legacy import write action',
    ]) {
        assert.ok(filterHelpArticles(members, query).length > 0, query);
    }
});

test('related navigation labels do not masquerade as article content', () => {
    assert.deepEqual(
        filterHelpArticles(
            [
                {
                    id: 'one',
                    title: 'Ordinary receipt',
                    related: [{ label: 'UniqueNavigationOnly' }],
                },
            ],
            'UniqueNavigationOnly',
        ),
        [],
    );
    assert.deepEqual(
        filterHelpArticles(finance, 'no-such-help-topic-unique'),
        [],
    );
});

test('search remains scoped to the selected section', () => {
    assert.deepEqual(
        filterHelpArticles(
            catalog.sections.find((section) => section.id === 'profile')
                .articles,
            'Match amount (INR)',
        ),
        [],
    );
});
