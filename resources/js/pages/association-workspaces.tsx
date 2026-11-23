import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState, type FormEvent } from 'react';
import { AssociationRegistrationDetails, type AssociationRegistrationRecord } from '@/components/association-registration-details';
import { RecordsPagination, RecordsTable } from '@/components/records-table';
import type { WorkspaceSettings } from '@/components/association-membership-fields';
import { Button } from '@/components/ui/button';

const workspaceUrl = '/association-workspaces';

type Workspace = AssociationRegistrationRecord;
type WorkspaceFilters = { q: string; sort: 'name' | 'industry' | 'city' | 'created_at'; direction: 'asc' | 'desc' };
type WorkspacePage = {
    data: Workspace[]; total: number; current_page: number; last_page: number;
    prev_page_url: string | null; next_page_url: string | null;
};

function WorkspaceRecords({ workspaces, filters }: { workspaces: WorkspacePage; filters: WorkspaceFilters }) {
    const [expanded, setExpanded] = useState<string | null>(null);
    const params = new URLSearchParams(filters);
    const columns = [['name', 'Association'], ['industry', 'Industry'], ['city', 'City'], ['created_at', 'Submitted']] as const;
    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(workspaceUrl, { ...filters, q: String(new FormData(event.currentTarget).get('q') ?? '').trim() });
    }
    function actions(item: Workspace) {
        return <div className="flex flex-wrap gap-2">
            <Button size="sm" asChild><Link href={`${workspaceUrl}/${item.id}`}>Open workspace</Link></Button>
            <Button size="sm" variant="outline" asChild><Link href={`${workspaceUrl}/${item.id}/setup`}>Workspace setup</Link></Button>
        </div>;
    }
    return <section className="grid min-w-0 gap-4" aria-label="Association workspaces">
        <form className="flex flex-wrap items-end gap-2" onSubmit={search}>
            <label className="grid w-full gap-2 text-sm font-medium sm:max-w-sm">Search workspaces
                <input name="q" type="search" maxLength={120} defaultValue={filters.q} placeholder="Association, city, email or reference"
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring" />
            </label>
            <Button type="submit" variant="outline">Search</Button>
            {filters.q && <Button variant="ghost" asChild><Link href={`${workspaceUrl}?${new URLSearchParams({ ...filters, q: '' })}`}>Clear search</Link></Button>}
        </form>
        <RecordsTable>
            <thead className="border-b bg-muted/40"><tr>
                {columns.map(([column, label]) => {
                    const active = filters.sort === column;
                    const direction = active && filters.direction === 'asc' ? 'desc' : 'asc';
                    return <th key={column} scope="col" className="p-3" aria-sort={active ? (filters.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                        <Link className="inline-flex items-center gap-2 hover:underline" href={`${workspaceUrl}?${new URLSearchParams({ ...Object.fromEntries(params), sort: column, direction })}`}>
                            {label}<span aria-hidden="true">{active ? (filters.direction === 'asc' ? '↑' : '↓') : '↕'}</span>
                        </Link>
                    </th>;
                })}
                <th scope="col" className="p-3">Actions</th>
            </tr></thead>
            <tbody>
                {workspaces.data.map((item) => {
                    const open = expanded === item.id;
                    return <Fragment key={item.id}>
                        <tr className="border-b">
                            <th scope="row" className="p-3 font-medium">
                                <button type="button" aria-expanded={open} aria-controls={open ? `workspace-${item.id}` : undefined}
                                    className="text-left hover:underline" onClick={() => setExpanded(open ? null : item.id)}>
                                    <span className="mr-2" aria-hidden="true">{open ? '▾' : '▸'}</span>{item.name}
                                </button>
                            </th>
                            <td className="p-3">{item.industry}</td><td className="p-3">{item.city}</td>
                            <td className="p-3 tabular-nums">{item.created_at}</td><td className="p-3">{actions(item)}</td>
                        </tr>
                        {open && <tr id={`workspace-${item.id}`} className="border-b bg-background/40">
                            <td colSpan={5} className="p-4"><div className="grid gap-4">
                                <AssociationRegistrationDetails application={item} />{actions(item)}
                            </div></td>
                        </tr>}
                    </Fragment>;
                })}
                {!workspaces.data.length && <tr><td colSpan={5} className="p-4 text-muted-foreground">No workspaces match this view.</td></tr>}
            </tbody>
        </RecordsTable>
        <RecordsPagination page={workspaces} label="Workspace pages" singular="workspace" plural="workspaces" />
    </section>;
}

export default function AssociationWorkspaces({ workspace, workspaces, branding, filters }: {
    branding?: WorkspaceSettings;
    filters?: WorkspaceFilters;
    workspace: Workspace | null;
    workspaces: WorkspacePage | null;
}) {
    const title = workspace ? branding?.display_name ?? workspace.name : 'Association dashboard';

    return (
        <div className="grid gap-4 p-4">
            <Head title={title} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                <Button variant="outline" asChild>
                    <Link href={workspace ? workspaceUrl : '/association-registration'}>
                        {workspace ? 'All workspaces' : 'My registrations'}
                    </Link>
                </Button>
            </div>
            {workspace && (
                <section className="grid gap-2 rounded-xl border border-t-4 bg-card p-5" style={{ borderTopColor: branding?.accent }}>
                    {branding?.logo_url && <img className="size-14 rounded-lg object-contain" src={branding.logo_url} alt="" />}
                    <h2 className="font-semibold">Workspace overview</h2>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline"><Link href={`/association-payments?association=${workspace.id}`}>Payments</Link></Button>
                        <Button asChild variant="outline"><Link href="/association-members">Members</Link></Button>
                    </div>
                    <p className="text-sm text-muted-foreground">{[workspace.organisation_type, workspace.industry, workspace.city].join(' · ')}</p>
                    <p className="text-sm">Your role · Association Admin</p>
                    {branding?.description && <p className="whitespace-pre-wrap break-words text-sm">{branding.description}</p>}
                    <div className="flex flex-wrap gap-2">
                        <Button asChild><Link href={`/association-membership-review?association=${workspace.id}`}>Review membership applications</Link></Button>
                        <Button asChild variant="outline"><Link href={`${workspaceUrl}/${workspace.id}/setup`}>Workspace setup</Link></Button>
                    </div>
                </section>
            )}
            {workspaces && filters && <WorkspaceRecords key={JSON.stringify([filters, workspaces.current_page])} workspaces={workspaces} filters={filters} />}

        </div>
    );
}

AssociationWorkspaces.layout = {
    breadcrumbs: [{ title: 'Association dashboard', href: workspaceUrl }],
};
