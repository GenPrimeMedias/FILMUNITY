import { Head, Link, router } from '@inertiajs/react';
import { Fragment, type FormEvent } from 'react';
import { ApplicationDetails, type ApplicationPage, type MembershipApplication } from '@/components/membership-application-list';
import { control } from '@/components/association-membership-fields';
import { RecordsPagination, RecordsTable } from '@/components/records-table';
import { Button } from '@/components/ui/button';

const url = '/filmunity-admin/members';
type Page<T> = {
    data: T[]; current_page: number; last_page: number; total: number;
    prev_page_url: string | null; next_page_url: string | null;
};
type Account = {
    id: number; name: string; email: string; email_verified_at: string | null;
    created_at: string; association_signup: boolean;
};
type Registration = {
    id: string; name: string; status: string; has_certificate: boolean;
    organisation_type: string; industry: string; city: string; representative_role: string;
    registration_number: string | null; registering_authority: string | null;
    state_or_region: string | null; registered_on: string | null; official_contact_email: string | null;
    registered_office_address: string | null; website: string | null; created_at: string;
    reviewed_at: string | null; review_note: string | null;
};
type Filters = {
    q: string; sort: 'id' | 'name' | 'email' | 'verified' | 'created_at';
    direction: 'asc' | 'desc'; verification: 'verified' | 'unverified' | null;
};

function Pagination<T>({ page, label }: { page: Page<T>; label: string }) {
    return <RecordsPagination page={page} label={label} />;
}

function RegistrationDetails({ registration }: { registration: Registration }) {
    const fields = [
        ['Organisation type', registration.organisation_type], ['Industry', registration.industry],
        ['City / region', [registration.city, registration.state_or_region].filter(Boolean).join(' · ')],
        ['Representative role', registration.representative_role], ['Registration number', registration.registration_number],
        ['Registering authority', registration.registering_authority], ['Date registered', registration.registered_on],
        ['Official contact email', registration.official_contact_email], ['Office address', registration.registered_office_address],
        ['Website', registration.website], ['Reference', registration.id],
        ['Submitted', registration.created_at], ['Decision recorded', registration.reviewed_at],
        ['Decision note', registration.review_note],
    ];

    return <article className="grid gap-3 rounded-lg border bg-background p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
            <h4 className="font-semibold">{registration.name}</h4>
            <span className="rounded-full bg-muted px-3 py-1 text-sm">{registration.status}</span>
        </div>
        <dl className="grid gap-3 text-sm md:grid-cols-2">
            {fields.filter(([, value]) => value).map(([label, value]) => <div key={label} className="min-w-0 break-words">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="whitespace-pre-wrap">{value}</dd>
            </div>)}
        </dl>
        {registration.has_certificate && <Button variant="outline" className="w-fit" asChild>
            <a href={`/association-registration/${registration.id}/certificate`}>Download registration certificate</a>
        </Button>}
    </article>;
}

export default function FilmunityAdminMembers({ members, filters, expanded }: {
    members: Page<Account>;
    filters: Filters;
    expanded: { id: number; registrations: Page<Registration>; applications: ApplicationPage<MembershipApplication> } | null;
}) {
    const params = new URLSearchParams({ sort: filters.sort, direction: filters.direction });
    if (filters.q) params.set('q', filters.q);
    if (filters.verification) params.set('verification', filters.verification);
    const columns: [Filters['sort'], string][] = [
        ['id', 'User ID'], ['name', 'Name'], ['email', 'Email'], ['verified', 'Email verification'], ['created_at', 'Registered'],
    ];

    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(url, { ...Object.fromEntries(params), q: String(new FormData(event.currentTarget).get('q') ?? '').trim() });
    }

    return <main className="grid min-w-0 gap-4 p-4">
        <Head title="Registered accounts" />
        <header className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-xl font-semibold tracking-tight">Registered accounts</h1>
            <Button asChild variant="outline"><Link href="/filmunity-admin">FILMUNITY Admin</Link></Button>
        </header>
        <nav className="flex flex-wrap gap-2" aria-label="Filter accounts by verification">
            {([[null, 'All accounts'], ['verified', 'Verified'], ['unverified', 'Unverified']] as const).map(([value, label]) => {
                const next = new URLSearchParams(params);
                if (value) next.set('verification', value);
                else next.delete('verification');
                return <Button key={label} variant={filters.verification === value ? 'default' : 'outline'} asChild>
                    <Link href={`${url}?${next}`}>{label}</Link>
                </Button>;
            })}
        </nav>
        <form className="flex flex-wrap items-end gap-2" onSubmit={search}>
            <label className="grid w-full gap-2 text-sm font-medium sm:max-w-sm">
                Search accounts
                <input key={filters.q} className={control} type="search" name="q" maxLength={120} defaultValue={filters.q} placeholder="Name, email or user ID" />
            </label>
            <Button type="submit" variant="outline">Search</Button>
            {filters.q && <Button asChild variant="ghost"><Link href={`${url}?${new URLSearchParams({ ...Object.fromEntries(params), q: '' })}`}>Clear search</Link></Button>}
        </form>
        <RecordsTable>
                <thead className="border-b bg-muted/40"><tr>
                    {columns.map(([column, label]) => {
                        const active = filters.sort === column;
                        const direction = active && filters.direction === 'asc' ? 'desc' : 'asc';
                        const next = new URLSearchParams({ ...Object.fromEntries(params), sort: column, direction });
                        return <th key={column} className="p-3" scope="col" aria-sort={active ? (filters.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                            <Link className="inline-flex items-center gap-2 hover:underline" href={`${url}?${next}`}>
                                {label}<span aria-hidden="true">{active ? (filters.direction === 'asc' ? '↑' : '↓') : '↕'}</span>
                            </Link>
                        </th>;
                    })}
                </tr></thead>
                <tbody>
                    {members.data.map((account) => {
                        const open = expanded?.id === account.id;
                        const next = new URLSearchParams({ ...Object.fromEntries(params), page: String(members.current_page) });
                        if (!open) next.set('account', String(account.id));
                        return <Fragment key={account.id}>
                            <tr className="border-b">
                                <td className="p-3">{account.id}</td>
                                <th className="p-3 font-medium" scope="row">
                                    <Link href={`${url}?${next}`} preserveScroll aria-expanded={open}
                                        aria-controls={open ? `account-${account.id}` : undefined} className="hover:underline">
                                        <span className="mr-2" aria-hidden="true">{open ? '▾' : '▸'}</span>{account.name}
                                    </Link>
                                </th>
                                <td className="p-3">{account.email}</td>
                                <td className="p-3">{account.email_verified_at ? 'Verified' : 'Unverified'}</td>
                                <td className="p-3 tabular-nums">{account.created_at}</td>
                            </tr>
                            {open && expanded && <tr id={`account-${account.id}`} className="border-b bg-background/40">
                                <td colSpan={5} className="p-4">
                                    <div className="grid gap-5">
                                        <p className="text-sm text-muted-foreground">
                                            {account.association_signup ? 'Association sign-up' : 'Individual sign-up'} · {account.email_verified_at ? `Verified ${account.email_verified_at}` : 'Email verification pending'}
                                        </p>
                                        <section className="grid gap-3">
                                            <h3 className="font-semibold">Association registrations</h3>
                                            {expanded.registrations.data.map((registration) => <RegistrationDetails key={registration.id} registration={registration} />)}
                                            {!expanded.registrations.total && <p className="text-sm text-muted-foreground">No association registrations.</p>}
                                            <Pagination page={expanded.registrations} label="Association registration pages" />
                                        </section>
                                        <section className="grid gap-3">
                                            <h3 className="font-semibold">Membership applications</h3>
                                            {expanded.applications.data.map((application) => <article key={application.id} className="grid gap-3 rounded-lg border bg-background p-4">
                                                <h4 className="font-semibold">{application.association_name}</h4>
                                                <ApplicationDetails application={application} reviewing />
                                            </article>)}
                                            {!expanded.applications.total && <p className="text-sm text-muted-foreground">No membership applications.</p>}
                                            <Pagination page={expanded.applications} label="Membership application pages" />
                                        </section>
                                    </div>
                                </td>
                            </tr>}
                        </Fragment>;
                    })}
                    {!members.data.length && <tr><td colSpan={5} className="p-4 text-muted-foreground">No accounts match this view.</td></tr>}
                </tbody>
        </RecordsTable>
        <Pagination page={members} label="Account pages" />
    </main>;
}

FilmunityAdminMembers.layout = {
    breadcrumbs: [
        { title: 'FILMUNITY Admin', href: '/filmunity-admin' },
        { title: 'Registered accounts', href: url },
    ],
};
