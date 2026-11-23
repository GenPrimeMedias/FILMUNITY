import { MembershipLifecycleActions, MembershipStatus, type MembershipState } from '@/components/membership-lifecycle';
import { Link, router, useForm } from '@inertiajs/react';
import { Fragment, useRef, useState, type FormEvent } from 'react';
import { MembershipPaymentActions, type PaymentApplication } from '@/components/membership-payment-actions';
import { RecordsPagination, RecordsTable } from '@/components/records-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { control } from '@/components/association-membership-fields';

const statuses = {
    pending: 'Pending review',
    accepted_for_payment: 'Approved',
    declined: 'Declined',
};

export type MembershipApplication = PaymentApplication & {
    id: string;
    association_name: string;
    association_logo_url?: string | null;
    membership?: MembershipState | null;
    communications?: { event: string; recipient: string; sent_at: string | null; attempts: number; last_error: string | null; created_at: string }[];
    industry: string;
    member_name?: string;
    member_email?: string;
    statement: string;
    membership_type: 'yearly' | 'lifetime' | null;
    fee: string | null;
    currency: string | null;
    status: keyof typeof statuses;
    decision_note: string | null;
    created_at: string;
    reviewed_at: string | null;
    form_snapshot: { name: string; email: string; answers: { label: string; description?: string | null; value: string | number | string[] | null; download_url?: string }[] } | null;
};

export type ApplicationPage<T extends MembershipApplication = MembershipApplication> = {
    data: T[];
    current_page: number;
    total: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type MembershipFilters = {
    status: 'pending' | 'approved' | 'declined' | null;
    q: string;
    sort: 'applicant' | 'association' | 'industry' | 'status' | 'submitted';
    direction: 'asc' | 'desc';
};

type Decision = 'accepted_for_payment' | 'declined';
type Mode = 'details' | Decision;

export function ApplicationDetails({ application, reviewing }: { application: MembershipApplication; reviewing: boolean }) {
    const entries = [
        ['Membership plan', application.plan_name ?? 'Choose after approval'],
        ['Membership type', application.membership_type === 'yearly' ? 'Yearly' : application.membership_type === 'lifetime' ? 'Life-Time' : 'Choose after approval'],
        ['Fee at submission', application.fee !== null ? `${application.currency} ${application.fee}` : 'Not recorded'],
        ['Status', statuses[application.status]],
        ['Association', application.association_name],
        ['Industry', application.industry],
        ['Application reference', application.id],
        ['Submitted', application.created_at],
        ['Decision recorded', application.reviewed_at],
        ['Note to applicant', application.decision_note],
        ['Application statement', application.statement],
        ...(reviewing ? [
            ['Applicant', application.form_snapshot?.name ?? application.member_name],
            ['Email', application.form_snapshot?.email ?? application.member_email],
        ] : []),
    ];

    return <div className="grid gap-3">
        <Button variant="outline" className="w-fit" asChild><a href={`/membership-applications/${application.id}/print`} target="_blank" rel="noreferrer">Print application / Save as PDF</a></Button>
        <fieldset className="rounded-xl border border-t-4 border-t-primary p-4"><legend className="px-2 text-sm font-semibold">Application record</legend><dl className="grid gap-3 text-sm md:grid-cols-2">
            {entries.filter(([, value]) => value).map(([label, value]) => <div key={label} className="min-w-0 rounded-lg border p-3 break-words">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="whitespace-pre-wrap">{value}</dd>
            </div>)}
            {application.form_snapshot?.answers.map(({ label, value, download_url, description }) => (
                <div key={label} className="min-w-0 rounded-lg border p-3 break-words">
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    {description && <dd className="whitespace-pre-wrap text-muted-foreground">{description}</dd>}
                    <dd className="whitespace-pre-wrap">{download_url
                        ? <a className="underline underline-offset-4" href={download_url}>Download document</a>
                        : Array.isArray(value) ? value.join(', ') : value ?? 'Unanswered'}</dd>
                </div>
            ))}
        </dl></fieldset>
        {!!application.communications?.length && <section className="grid gap-3 rounded-xl border border-t-4 border-t-primary p-4"><h3 className="font-semibold">Application communications</h3>{application.communications?.map((notice, index) => <div className="rounded-lg border p-3 text-sm" key={index}><p className="font-medium">{notice.event.replaceAll('_', ' ')}</p><p className="break-all text-muted-foreground">{notice.recipient}</p><p>{notice.sent_at ? `Sent · ${notice.sent_at}` : notice.last_error ?? 'Pending delivery'}</p></div>)}{application.communications?.some((notice) => !notice.sent_at && notice.attempts < 5) && <Button type="button" variant="outline" className="w-fit" onClick={() => router.post(`/membership-applications/${application.id}/retry-notices`, {}, { preserveScroll: true })}>Retry pending application emails</Button>}</section>}
        {application.status === 'accepted_for_payment' && <p className="text-sm text-muted-foreground">{
            application.payment?.status === 'acknowledged' ? 'Payment acknowledged. Open your membership to check validity.'
                : application.payment?.status === 'submitted' ? 'Payment submitted for association verification.'
                : application.payment?.status === 'requested' ? 'The association has requested payment.'
                : 'Awaiting a payment request from the association.'
        }</p>}
    </div>;
}

function ConfirmDecision({ application, decision, href, onClose }: {
    application: MembershipApplication;
    decision: Decision;
    href: string;
    onClose: () => void;
}) {
    const form = useForm({ status: decision, decision_note: '' });
    const declining = decision === 'declined';

    return <>
        <DialogHeader>
            <DialogTitle>{declining ? 'Decline application?' : 'Approve application?'}</DialogTitle>
            <DialogDescription>{declining ? 'The applicant will be able to see your reason.' : 'Approval marks the application as awaiting a payment request.'}</DialogDescription>
        </DialogHeader>
        <form className="grid gap-3" onSubmit={(event) => {
            event.preventDefault();
            form.patch(href, { preserveScroll: true, onSuccess: onClose });
        }}>
            <label className="grid gap-2 text-sm font-medium">
                {declining ? 'Reason for declining' : 'Note to applicant (optional)'}
                <textarea className={control} rows={4} required={declining} maxLength={2000}
                    value={form.data.decision_note} onChange={(event) => form.setData('decision_note', event.target.value)} />
            </label>
            {Object.values(form.errors).map((error, index) => <p key={index} role="alert" className="text-sm text-destructive">{error}</p>)}
            <div className="flex flex-wrap justify-end gap-2">
                <Button type="button" variant="outline" disabled={form.processing} onClick={onClose}>Cancel</Button>
                <Button type="submit" variant={declining ? 'destructive' : 'default'} disabled={form.processing}>
                    {declining ? 'Confirm decline' : 'Confirm approval'}
                </Button>
            </div>
        </form>
        <span className="sr-only">Application {application.id}</span>
    </>;
}

export function MembershipApplicationList({ applications, filters, url, association, reviewing = false }: {
    applications: ApplicationPage;
    filters: MembershipFilters;
    url: string;
    association?: string | null;
    reviewing?: boolean;
}) {
    const [expanded, setExpanded] = useState<string | null>(null);
    const [dialog, setDialog] = useState<{ id: string; mode: Mode } | null>(null);
    const opener = useRef<HTMLButtonElement>(null);
    const selected = applications.data.find(({ id }) => id === dialog?.id);
    const params = new URLSearchParams({ sort: filters.sort, direction: filters.direction });
    if (filters.status) params.set('status', filters.status);
    if (filters.q) params.set('q', filters.q);
    if (association) params.set('association', association);
    const columns: [MembershipFilters['sort'], string][] = [
        ['association', 'Association'], ['industry', 'Industry'], ['status', 'Status'], ['submitted', 'Submitted'],
    ];
    if (reviewing) columns.unshift(['applicant', 'Applicant']);
    const span = columns.length + 1;
    const name = (application: MembershipApplication) => application.form_snapshot?.name ?? application.member_name ?? 'Applicant';
    const rowTitle = (application: MembershipApplication) => reviewing ? name(application) : application.association_name;

    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const q = String(new FormData(event.currentTarget).get('q') ?? '').trim();
        router.get(url, { ...Object.fromEntries(params), q }, { preserveScroll: true });
    }

    function actions(application: MembershipApplication, includeReview: boolean) {
        function open(mode: Mode, button: HTMLButtonElement) {
            if (!dialog) opener.current = button;
            setDialog({ id: application.id, mode });
        }

        return <div className="flex flex-wrap gap-2">
            <MembershipPaymentActions application={application} reviewing={reviewing} />
            {application.membership && <MembershipLifecycleActions membership={application.membership} reviewing={reviewing} />}
            {includeReview && <Button size="sm" variant="outline" onClick={(event) => open('details', event.currentTarget)}>{reviewing ? 'Review' : 'View'}</Button>}
            {reviewing && application.status === 'pending' && <>
                <Button size="sm" onClick={(event) => open('accepted_for_payment', event.currentTarget)}>Approve</Button>
                <Button size="sm" variant="destructive" onClick={(event) => open('declined', event.currentTarget)}>Decline</Button>
            </>}
        </div>;
    }

    return <section className="grid min-w-0 gap-4" aria-label="Membership applications">
        <nav className="flex flex-wrap gap-2" aria-label="Filter applications by status">
            {([
                [null, reviewing ? 'All registrations' : 'All applications'],
                ['pending', 'Pending review'], ['approved', 'Approved'], ['declined', 'Declined'],
            ] as const).map(([value, label]) => {
                const next = new URLSearchParams(params);
                if (value) next.set('status', value);
                else next.delete('status');
                const active = filters.status === value;
                return <Button key={label} variant={active ? 'default' : 'outline'} asChild>
                    <Link href={`${url}?${next}`} preserveScroll aria-current={active ? 'page' : undefined}>{label}</Link>
                </Button>;
            })}
        </nav>
        <form className="flex flex-wrap items-end gap-2" onSubmit={search}>
            <label className="grid w-full gap-2 text-sm font-medium sm:max-w-sm">
                Search applications
                <input className={control} name="q" type="search" maxLength={120} defaultValue={filters.q}
                    placeholder={reviewing ? 'Applicant, association, email or reference' : 'Association, industry or reference'} />
            </label>
            <Button type="submit" variant="outline">Search</Button>
            {filters.q && <Button variant="ghost" asChild>
                <Link href={`${url}?${new URLSearchParams({ ...Object.fromEntries(params), q: '' })}`} preserveScroll>Clear search</Link>
            </Button>}
        </form>
        <RecordsTable>
                <thead className="border-b bg-muted/40">
                    <tr>
                        {columns.map(([column, label]) => {
                            const active = filters.sort === column;
                            const direction = active && filters.direction === 'asc' ? 'desc' : 'asc';
                            const next = new URLSearchParams({ ...Object.fromEntries(params), sort: column, direction });
                            return <th key={column} scope="col" className="p-3"
                                aria-sort={active ? (filters.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                                <Link className="inline-flex items-center gap-2 hover:underline" href={`${url}?${next}`} preserveScroll>
                                    {label}<span aria-hidden="true">{active ? (filters.direction === 'asc' ? '↑' : '↓') : '↕'}</span>
                                </Link>
                            </th>;
                        })}
                        <th scope="col" className="p-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {applications.data.map((application) => {
                        const open = expanded === application.id;
                        return <Fragment key={application.id}>
                            <tr className="border-b">
                                <th scope="row" className="p-3 font-medium">
                                    <button type="button" className="inline-flex items-center gap-2 text-left hover:underline" aria-expanded={open}
                                        aria-controls={open ? `details-${application.id}` : undefined}
                                        onClick={() => setExpanded(open ? null : application.id)}>
                                        <span aria-hidden="true" className="mr-2">{open ? '▾' : '▸'}</span>{!reviewing && application.association_logo_url && <img src={application.association_logo_url} alt="" className="size-9 shrink-0 rounded-md border bg-background object-contain" />}{rowTitle(application)}
                                    </button>
                                </th>
                                {reviewing && <td className="p-3">{application.association_name}</td>}
                                <td className="p-3">{application.industry}</td>
                                <td className="p-3"><span className="rounded-full bg-muted px-3 py-1">{statuses[application.status]}</span>{application.membership && <div className="mt-2"><MembershipStatus status={application.membership.display_status} /></div>}</td>
                                <td className="p-3 tabular-nums">{application.created_at}</td>
                                <td className="p-3">{actions(application, true)}</td>
                            </tr>
                            {open && <tr id={`details-${application.id}`} className="border-b bg-background/40">
                                <td colSpan={span} className="p-4"><div className="grid gap-4">
                                    <ApplicationDetails application={application} reviewing={reviewing} />
                                    {actions(application, true)}
                                </div></td>
                            </tr>}
                        </Fragment>;
                    })}
                    {!applications.data.length && <tr><td colSpan={span} className="p-4 text-muted-foreground">No applications match this view.</td></tr>}
                </tbody>
        </RecordsTable>
        <RecordsPagination page={applications} label="Membership application pages" singular="application" plural="applications" />
        <Dialog open={Boolean(dialog && selected)} onOpenChange={(open) => { if (!open) setDialog(null); }}>
            {selected && dialog && <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-2xl" onCloseAutoFocus={(event) => {
                if (opener.current?.isConnected) {
                    event.preventDefault();
                    opener.current.focus();
                }
            }}>
                {dialog.mode === 'details' ? <>
                    <DialogHeader><DialogTitle>{rowTitle(selected)}</DialogTitle>
                        <DialogDescription>Membership application details</DialogDescription></DialogHeader>
                    <ApplicationDetails application={selected} reviewing={reviewing} />
                    {actions(selected, false)}
                </> : <ConfirmDecision key={dialog.mode} application={selected} decision={dialog.mode}
                    href={`${url}/${selected.id}?${params}`} onClose={() => setDialog(null)} />}
            </DialogContent>}
        </Dialog>
    </section>;
}
