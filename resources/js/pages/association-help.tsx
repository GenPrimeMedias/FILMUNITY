import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, BookOpen, Download, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { filterHelpArticles } from '@/lib/help-search';
type Article = {
    id: string;
    title: string;
    why: string;
    before: string[];
    steps: string[];
    result: string;
    example: string;
    avoid: string[];
    table: string[][];
    terms: string[][];
    fields?: {
        label: string;
        purpose: string;
        required: string;
        example: string;
        validation: string;
    }[];
    related?: { section: string; topic: string; label: string }[];
};
type Section = {
    id: string;
    title: string;
    summary: string;
    articles: Article[];
};
type Catalog = {
    version: string;
    sections: Section[];
    source: { label: string; url: string; note: string };
};
const panel =
    'rounded-xl border border-t-4 border-t-[#b9d66b] bg-card p-5 text-card-foreground';
export default function AssociationHelp({
    catalog,
    sectionId,
    topicId,
    canManageAssociation,
}: {
    catalog: Catalog;
    sectionId: string;
    topicId: string;
    canManageAssociation: boolean;
}) {
    const [search, setSearch] = useState('');
    const { filmunityNavigation } = usePage<{
        filmunityNavigation?: { associationWorkspaceId?: string };
    }>().props;
    const section = catalog.sections.find((s) => s.id === sectionId)!;
    const article = section.articles.find((a) => a.id === topicId)!;
    const results = filterHelpArticles(section.articles, search);
    const index = section.articles.findIndex((a) => a.id === topicId);
    const topicHref = (id: string) =>
        `/association-help/${sectionId}?topic=${id}`;
    const returnHref =
        sectionId === 'profile'
            ? '/dashboard?panel=profile'
            : sectionId === 'finance'
              ? '/association-finance'
              : sectionId === 'members'
                ? '/association-membership-review'
                : '/association-workspaces';
    const setup = filmunityNavigation?.associationWorkspaceId
        ? `/association-workspaces/${filmunityNavigation.associationWorkspaceId}/setup`
        : '/association-workspaces';
    return (
        <main className="grid min-w-0 gap-5 p-4 md:p-6 print:p-0">
            <Head title={`${article.title} — FILMUNITY help`}>
                <link rel="stylesheet" href="/print.css" />
            </Head>
            <style>
                {
                    '@media print { [data-sidebar="sidebar"], [data-slot="sidebar"], [data-slot="sidebar-container"], .help-controls { display:none!important; } .help-article { border:0!important; background:white!important; color:black!important; padding:0!important; } .help-article section { break-inside:avoid; } }'
                }
            </style>
            <nav
                aria-label="Help breadcrumbs"
                className="help-controls flex flex-wrap gap-2 text-sm"
            >
                <Link
                    className="underline"
                    href={
                        canManageAssociation
                            ? '/association-workspaces'
                            : '/dashboard'
                    }
                >
                    {canManageAssociation
                        ? 'Association dashboard'
                        : 'Dashboard'}
                </Link>
                <span aria-hidden="true">›</span>
                <Link
                    className="underline"
                    href={`/association-help/${sectionId}`}
                >
                    {section.title} help
                </Link>
                <span aria-hidden="true">›</span>
                <span aria-current="page">{article.title}</span>
            </nav>
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="mb-1 flex items-center gap-2 text-sm text-muted-foreground">
                        <BookOpen className="size-4" aria-hidden="true" />{' '}
                        FILMUNITY Help Centre
                    </p>
                    <h1 className="text-2xl font-semibold">{section.title}</h1>
                    <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                        {section.summary}
                    </p>
                </div>
                <div className="help-controls flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={returnHref}>
                            <ArrowLeft />
                            Return to section
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <a href={`/association-help/${sectionId}/download`}>
                            <Download />
                            Download guide
                        </a>
                    </Button>
                    <Button variant="outline" onClick={() => window.print()}>
                        <Printer />
                        Print / Save as PDF
                    </Button>
                </div>
            </header>
            <nav
                className="help-controls flex flex-wrap gap-2"
                aria-label="Help sections"
            >
                {catalog.sections.map((s) => (
                    <Button
                        key={s.id}
                        variant={s.id === sectionId ? 'default' : 'outline'}
                        asChild
                    >
                        <Link
                            href={`/association-help/${s.id}`}
                            aria-current={
                                s.id === sectionId ? 'page' : undefined
                            }
                        >
                            {s.title}
                        </Link>
                    </Button>
                ))}
            </nav>
            <div className="grid min-w-0 items-start gap-5 lg:grid-cols-[18rem_minmax(0,1fr)] print:block">
                <aside
                    className={`${panel} help-controls grid gap-3 lg:sticky lg:top-5`}
                >
                    <label className="grid gap-2 text-sm font-medium">
                        Find a how-to
                        <input
                            type="search"
                            maxLength={120}
                            className="min-w-0 rounded-lg border bg-background px-3 py-2 font-normal"
                            placeholder="Try rent, bank or renewal"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </label>
                    <p
                        className="text-xs text-muted-foreground"
                        aria-live="polite"
                    >
                        {results.length} matching{' '}
                        {results.length === 1 ? 'article' : 'articles'}
                    </p>
                    <nav
                        className="grid max-h-[60dvh] gap-1 overflow-y-auto"
                        aria-label="Help topics"
                    >
                        {results.map((a) => (
                            <Link
                                className={`rounded-lg px-3 py-2 text-sm hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary ${a.id === topicId ? 'bg-primary/15 font-semibold text-primary' : ''}`}
                                key={a.id}
                                href={topicHref(a.id)}
                                aria-current={
                                    a.id === topicId ? 'page' : undefined
                                }
                            >
                                {a.title}
                            </Link>
                        ))}
                        {!results.length && (
                            <p className="text-sm">
                                No matches. Try a shorter phrase or another
                                section.
                            </p>
                        )}
                    </nav>
                </aside>
                <article className={`${panel} help-article grid min-w-0 gap-7`}>
                    <header>
                        <p className="text-xs text-muted-foreground">
                            Guide updated {catalog.version}
                        </p>
                        <h2 className="mt-2 text-2xl font-semibold">
                            {article.title}
                        </h2>
                        <p className="mt-3 leading-relaxed">{article.why}</p>
                    </header>
                    <section aria-labelledby="help-before">
                        <h3
                            id="help-before"
                            className="mb-3 text-lg font-semibold"
                        >
                            Before you begin
                        </h3>
                        <ul className="list-disc space-y-2 pl-5">
                            {article.before.map((v, i) => (
                                <li key={i}>{v}</li>
                            ))}
                        </ul>
                    </section>
                    {!!article.terms.length && (
                        <section aria-labelledby="help-terms">
                            <h3
                                id="help-terms"
                                className="mb-3 text-lg font-semibold"
                            >
                                Plain-language terms
                            </h3>
                            <dl className="grid gap-3">
                                {article.terms.map(([term, meaning]) => (
                                    <div
                                        className="rounded-lg border p-3"
                                        key={term}
                                    >
                                        <dt className="font-semibold">
                                            {term}
                                        </dt>
                                        <dd className="mt-1 text-sm leading-relaxed">
                                            {meaning}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    )}
                    {!!article.fields?.length && (
                        <section aria-labelledby="help-fields">
                            <h3
                                id="help-fields"
                                className="mb-3 text-lg font-semibold"
                            >
                                Fields in screen order
                            </h3>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <caption className="sr-only">
                                        Field reference for {article.title}
                                    </caption>
                                    <thead>
                                        <tr>
                                            {[
                                                'Field',
                                                'Purpose',
                                                'Required?',
                                                'Synthetic example',
                                                'Format and validation',
                                            ].map((label) => (
                                                <th
                                                    scope="col"
                                                    className="border-b p-2"
                                                    key={label}
                                                >
                                                    {label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {article.fields.map((field, i) => (
                                            <tr key={i}>
                                                {[
                                                    field.label,
                                                    field.purpose,
                                                    field.required,
                                                    field.example,
                                                    field.validation,
                                                ].map((value, j) => (
                                                    <td
                                                        className="border-b p-2 align-top break-words"
                                                        key={j}
                                                    >
                                                        {value}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}
                    <section aria-labelledby="help-steps">
                        <h3
                            id="help-steps"
                            className="mb-4 text-lg font-semibold"
                        >
                            Step by step
                        </h3>
                        <ol className="list-decimal space-y-4 pl-6 marker:font-bold marker:text-primary">
                            {article.steps.map((v, i) => (
                                <li className="pl-2 leading-relaxed" key={i}>
                                    {v}
                                </li>
                            ))}
                        </ol>
                    </section>
                    {(article.example || article.table.length > 0) && (
                        <section
                            className="rounded-xl border border-t-4 border-t-primary p-4"
                            aria-labelledby="help-example"
                        >
                            <h3
                                id="help-example"
                                className="mb-3 text-lg font-semibold"
                            >
                                Worked example
                            </h3>
                            {article.example && (
                                <p className="leading-relaxed">
                                    {article.example}
                                </p>
                            )}
                            {!!article.table.length && (
                                <div className="mt-3 overflow-x-auto">
                                    <table className="w-full min-w-[32rem] text-left text-sm">
                                        <caption className="sr-only">
                                            Illustrative entries for{' '}
                                            {article.title}
                                        </caption>
                                        <thead>
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="border-b p-3"
                                                >
                                                    Event
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="border-b p-3"
                                                >
                                                    Debit
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="border-b p-3"
                                                >
                                                    Credit
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {article.table.map((row, i) => (
                                                <tr key={i}>
                                                    {row.map((value, j) => (
                                                        <td
                                                            key={j}
                                                            className="border-b p-3 align-top"
                                                        >
                                                            {value}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    )}
                    <section
                        className="rounded-lg bg-primary/10 p-4"
                        aria-labelledby="help-result"
                    >
                        <h3 id="help-result" className="mb-2 font-semibold">
                            What you should see afterwards
                        </h3>
                        <p className="leading-relaxed">{article.result}</p>
                    </section>
                    {!!article.avoid.length && (
                        <section aria-labelledby="help-avoid">
                            <h3
                                id="help-avoid"
                                className="mb-3 text-lg font-semibold"
                            >
                                Common mistakes and current limits
                            </h3>
                            <ul className="list-disc space-y-3 pl-5">
                                {article.avoid.map((v, i) => (
                                    <li key={i} className="leading-relaxed">
                                        {v}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                    {!!article.related?.length && (
                        <nav
                            className="help-controls grid gap-2 border-t pt-4"
                            aria-label="Related tasks"
                        >
                            <h3 className="font-semibold">Related tasks</h3>
                            {article.related.map((link) => (
                                <Link
                                    className="underline"
                                    key={`${link.section}/${link.topic}`}
                                    href={`/association-help/${link.section}?topic=${link.topic}`}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </nav>
                    )}
                    {sectionId === 'finance' && (
                        <section className="border-t pt-4 text-sm text-muted-foreground">
                            <h3 className="font-semibold">
                                Accounting reference
                            </h3>
                            <p className="mt-2">{catalog.source.note}</p>
                            <a
                                className="mt-2 inline-block underline"
                                href={catalog.source.url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {catalog.source.label}
                            </a>
                        </section>
                    )}
                    <footer className="help-controls grid gap-4 border-t pt-4">
                        <div className="flex flex-wrap justify-between gap-2">
                            {index > 0 && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={topicHref(
                                            section.articles[index - 1].id,
                                        )}
                                    >
                                        Previous how-to
                                    </Link>
                                </Button>
                            )}
                            {index < section.articles.length - 1 && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={topicHref(
                                            section.articles[index + 1].id,
                                        )}
                                    >
                                        Next how-to
                                    </Link>
                                </Button>
                            )}
                        </div>
                        {canManageAssociation &&
                            sectionId === 'association' && (
                                <Button className="w-fit" asChild>
                                    <Link href={setup}>
                                        Open Workspace setup
                                    </Link>
                                </Button>
                            )}
                    </footer>
                </article>
            </div>
        </main>
    );
}
