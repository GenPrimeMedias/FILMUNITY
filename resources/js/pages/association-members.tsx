import { MembershipRowActions } from '@/components/membership-row-actions';
import { type ReminderState } from '@/components/membership-reminder-action';
import { MembershipStatus, MembershipTypePill, membershipDate } from '@/components/membership-lifecycle';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useState, type FormEvent } from 'react';
import { control } from '@/components/association-membership-fields';
import { type RecordPage } from '@/components/payment-records';
import { RecordsPagination, RecordsTable } from '@/components/records-table';
import { Button } from '@/components/ui/button';

type Membership = {
    plan_name?: string; id: string; number: string; membership_type: string; display_status: string; starts_at: string;
    ends_at: string | null; payment_id: string; association_name: string; member_name: string; canEnter: boolean; reminder?: ReminderState | null; canRenew: boolean; logo_url?: string | null; renewal_payment?: { id: string; status: string } | null;
};
type Filters = { status: string; q: string; sort: string; direction: 'asc' | 'desc' };
export default function AssociationMembers({ memberships, filters, scope, renewalQueue = false }: {
    memberships: RecordPage<Membership>; filters: Filters; scope: 'admin' | 'member'; renewalQueue?: boolean;
}) {
    const { url: pageUrl } = usePage();
    const workspaceDirectory = scope === 'member' && pageUrl.split('?')[0] === '/my-association-workspaces';
    const renewalView = scope === 'admin' && renewalQueue;
    const title = scope === 'admin' ? renewalView ? 'Members renewal' : 'Members' : workspaceDirectory ? 'My association workspaces' : 'My memberships and renewals';
    const navigationFilters = renewalView ? { ...filters, view: 'renewals' } : filters;
    const keepRenewalView = (href: string | null) => renewalView && href ? `${href}${href.includes('?') ? '&' : '?'}view=renewals` : href;
    const navigationMemberships = { ...memberships, prev_page_url: keepRenewalView(memberships.prev_page_url), next_page_url: keepRenewalView(memberships.next_page_url) };
    const url = scope === 'admin' ? '/association-members' : workspaceDirectory ? '/my-association-workspaces' : '/membership-renewals';
    return <main className="grid min-w-0 gap-4 p-4"><Head title={title} />
        <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
        {scope === 'admin' && <Button className="w-fit" asChild variant="outline"><Link href="/association-members/legacy-preview">Legacy membership import preview</Link></Button>}
        {renewalQueue && <section className="rounded-xl border border-t-4 border-t-primary bg-card p-4 text-sm"><h2 className="font-semibold">Yearly memberships expiring within 30 days</h2><p className="mt-2 text-muted-foreground">Only active yearly memberships whose expiry is after now and within the next 30 days appear here. Reminder emails are sent to the member after confirmation. Expired members remain available under Members → Expired.</p></section>}
        <MembershipRows key={JSON.stringify([navigationFilters, memberships.current_page])} memberships={navigationMemberships} filters={navigationFilters} url={url} admin={scope === 'admin'} renewalQueue={renewalQueue} />
    </main>;
}

function MembershipRows({ memberships, filters, url, admin, renewalQueue }: { memberships: RecordPage<Membership>; filters: Filters; url: string; admin: boolean; renewalQueue: boolean }) {
    const [expanded, setExpanded] = useState<string | null>(null);
    const columns = [['number', 'Membership number'], ['association', 'Association'], ['member', 'Member'], ['starts_at', 'Starts'], ['ends_at', 'Valid until']] as const;
    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(url, { ...filters, q: String(new FormData(event.currentTarget).get('q') ?? '').trim() });
    }
    function actions(item: Membership) {
        return <MembershipRowActions item={item} admin={admin} renewalQueue={renewalQueue} />;
    }
    return <section className="grid min-w-0 gap-4">
        {!renewalQueue && <nav className="flex flex-wrap gap-2" aria-label="Filter memberships by status">
            {([['', 'All memberships'], ['active', 'Active'], ['expiring_soon', 'Expiring soon'], ['expired', 'Expired'], ['lifetime', 'Life-Time'], ['suspended', 'Suspended']] as const).map(([status, label]) => <Button key={label} variant={filters.status === status ? 'default' : 'outline'} asChild><Link href={`${url}?${new URLSearchParams({ ...filters, status })}`} aria-current={filters.status === status ? 'page' : undefined}>{label}</Link></Button>)}
        </nav>}
        <form className="flex flex-wrap items-end gap-2" onSubmit={search}>
            <label className="grid w-full gap-2 text-sm font-medium sm:max-w-sm">Search memberships
                <input className={control} type="search" name="q" defaultValue={filters.q} maxLength={120} placeholder="Association, member or membership number" />
            </label>
            <Button type="submit" variant="outline">Search</Button>
            {filters.q && <Button asChild variant="ghost"><Link href={`${url}?${new URLSearchParams({ ...filters, q: '' })}`}>Clear search</Link></Button>}
        </form>
        <RecordsTable>
            <thead className="border-b bg-muted/40"><tr>
                {columns.map(([column, label]) => {
                    const active = filters.sort === column;
                    const direction = active && filters.direction === 'asc' ? 'desc' : 'asc';
                    return <th key={column} scope="col" className="p-3 whitespace-nowrap" aria-sort={active ? (filters.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                        <Link className="inline-flex items-center gap-2 hover:underline" href={`${url}?${new URLSearchParams({ ...filters, sort: column, direction })}`}>{label}<span aria-hidden="true">{active ? (filters.direction === 'asc' ? '↑' : '↓') : '↕'}</span></Link>
                    </th>;
                })}
                <th scope="col" className="p-3 whitespace-nowrap" aria-sort={filters.sort === 'status' ? filters.direction === 'asc' ? 'ascending' : 'descending' : undefined}><Link href={`${url}?${new URLSearchParams({ ...filters, sort: 'status', direction: filters.sort === 'status' && filters.direction === 'asc' ? 'desc' : 'asc' })}`}>Status {filters.sort === 'status' ? filters.direction === 'asc' ? '↑' : '↓' : '↕'}</Link></th><th scope="col" className="p-3 whitespace-nowrap">Actions</th>
            </tr></thead>
            <tbody>{memberships.data.map((item) => {
                const open = expanded === item.id;
                return <Fragment key={item.id}>
                    <tr className="border-b">
                        <th scope="row" className="p-3 whitespace-nowrap font-medium"><button type="button" className="text-left hover:underline" aria-expanded={open}
                            aria-controls={open ? `membership-${item.id}` : undefined} onClick={() => setExpanded(open ? null : item.id)}>
                            <span className="mr-2" aria-hidden="true">{open ? '▾' : '▸'}</span>{item.number}
                        </button></th>
                        <td className="p-3"><span className="flex max-w-72 items-center gap-3">{item.logo_url && <img src={item.logo_url} alt="" className="size-9 shrink-0 rounded-md object-contain" />}{item.association_name}</span></td><td className="p-3">{item.member_name}</td><td className="p-3 whitespace-nowrap">{membershipDate(item.starts_at)}</td><td className="p-3 whitespace-nowrap">{membershipDate(item.ends_at)}</td>
                        <td className="p-3"><MembershipStatus status={item.display_status} /></td><td className="w-px p-3 align-top">{actions(item)}</td>
                    </tr>
                    {open && <tr id={`membership-${item.id}`} className="border-b bg-background/40"><td colSpan={7} className="p-4">
                        <div className="grid gap-3 rounded-lg border border-t-4 border-t-primary p-4 text-sm"><div><MembershipTypePill type={item.membership_type} />{item.plan_name && <p className="mt-2 font-medium">Plan · {item.plan_name}</p>}</div>
                            <p>Valid until · {membershipDate(item.ends_at)}</p>{actions(item)}
                        </div>
                    </td></tr>}
                </Fragment>;
            })}
            {!memberships.data.length && <tr><td colSpan={7} className="p-4 text-muted-foreground">{renewalQueue ? 'No yearly memberships are expiring within the next 30 days.' : 'No memberships match this view.'}</td></tr>}
            </tbody>
        </RecordsTable>
        <RecordsPagination page={memberships} label="Membership pages" singular="membership" plural="memberships" />
    </section>;
}
AssociationMembers.layout = { breadcrumbs: [{ title: 'Memberships', href: '/membership-renewals' }] };
