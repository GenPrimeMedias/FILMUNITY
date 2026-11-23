import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Fragment, useRef, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';

import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const registrationUrl = '/association-registration';
const statusLabels = {
    pending: 'Pending review',
    approved: 'Approved',
    declined: 'Declined',
};

type Application = {
    id: string;
    name: string;
    organisation_type: string;
    industry: string;
    city: string;
    representative_role: string;
    status: keyof typeof statusLabels;
    review_note: string | null;
    registration_number: string | null;
    registering_authority: string | null;
    state_or_region: string | null;
    registered_on: string | null;
    has_certificate: boolean;
    official_contact_email: string | null;
    registered_office_address: string | null;
    website: string | null;
    created_at: string;
    reviewed_at: string | null;
    approval_email_sent_at: string | null;
};

const card = 'grid gap-3 rounded-xl border bg-card p-5 text-card-foreground';
const field = 'grid min-w-0 gap-2 text-sm font-medium';
const input = 'w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring';

function RegistrationForm({ types, industries }: { types: string[]; industries: string[] }) {
    const form = useForm({
        name: '', organisation_type: '', industry: '', city: '', representative_role: '',
        registration_number: '', registering_authority: '', state_or_region: '', registered_on: '',
        official_contact_email: '', registered_office_address: '', website: '',
        certificate: null as File | null,
    });
    const certificateInput = useRef<HTMLInputElement>(null);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(registrationUrl, {
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                if (certificateInput.current) certificateInput.current.value = '';
            },
        });
    }

    return (
        <form className={card} onSubmit={submit}>
            <h2 className="font-semibold">Register an association or union</h2>
            <fieldset className="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                <legend className="px-2 text-sm font-semibold">Organisation details</legend>
                {([
                    ['name', 'Legal organisation name', 160],
                    ['city', 'City', 100],
                    ['state_or_region', 'State or region', 100],
                ] as const).map(([key, label, maxLength]) => (
                    <label className={field} key={key}>
                        {label}
                        <input className={input} required maxLength={maxLength} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} />
                    </label>
                ))}
                {([
                    ['organisation_type', 'Organisation type', types],
                    ['industry', 'Film industry', industries],
                ] as const).map(([key, label, options]) => (
                    <label className={field} key={key}>
                        {label}
                        <select className={input} required value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)}>
                            <option value="">Select</option>
                            {options.map((option) => <option key={option}>{option}</option>)}
                        </select>
                    </label>
                ))}
            </fieldset>
            <fieldset className="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                <legend className="px-2 text-sm font-semibold">Registration and certificate</legend>
                {([
                    ['registration_number', 'Registration number', 100],
                    ['registering_authority', 'Registering authority', 160],
                ] as const).map(([key, label, maxLength]) => (
                    <label className={field} key={key}>
                        {label}
                        <input className={input} required maxLength={maxLength} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} />
                    </label>
                ))}
                <label className={field}>
                    Date registered (optional)
                    <input className={input} type="date" value={form.data.registered_on} onChange={(event) => form.setData('registered_on', event.target.value)} />
                </label>
                <label className={field}>
                    Registration certificate
                    <input ref={certificateInput} className={input} type="file" accept=".pdf,.jpg,.jpeg,.png" required aria-describedby="registration-certificate-help" onChange={(event) => form.setData('certificate', event.target.files?.[0] ?? null)} />
                    <span id="registration-certificate-help" className="text-xs text-muted-foreground">One PDF, JPEG or PNG, up to 5 MB.</span>
                </label>
            </fieldset>
            <fieldset className="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                <legend className="px-2 text-sm font-semibold">Official contact details</legend>
                <label className={field}>
                    Official contact email
                    <input className={input} type="email" required maxLength={254} value={form.data.official_contact_email} onChange={(event) => form.setData('official_contact_email', event.target.value)} />
                    <span className="text-xs text-muted-foreground">Approval confirmation will be sent here. You may use the same email address as your representative account.</span>
                </label>
                <label className={field}>
                    Website (optional)
                    <input className={input} type="url" placeholder="https://example.org" maxLength={2048} value={form.data.website} onChange={(event) => form.setData('website', event.target.value)} />
                </label>
                <label className={`${field} md:col-span-2`}>
                    Registered office address
                    <textarea className={input} rows={3} required maxLength={1000} value={form.data.registered_office_address} onChange={(event) => form.setData('registered_office_address', event.target.value)} />
                </label>
            </fieldset>
            <fieldset className="grid gap-4 rounded-lg border p-4">
                <legend className="px-2 text-sm font-semibold">Authorised representative</legend>
                <label className={field}>
                    Your role in the organisation
                    <input className={input} required maxLength={100} value={form.data.representative_role} onChange={(event) => form.setData('representative_role', event.target.value)} />
                </label>
            </fieldset>
            {Object.values(form.errors).map((error, index) => <p key={index} role="alert" className="text-sm text-destructive">{error}</p>)}
            <Button className="w-fit" disabled={form.processing}>Submit for review</Button>
        </form>
    );
}

type SortColumn = 'name' | 'industry' | 'status' | 'created_at';
type QueueFilters = { q: string; sort: SortColumn; direction: 'asc' | 'desc' };
type ApplicationPage = {
    data: Application[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type DialogMode = 'details' | 'approved' | 'declined';

function ApplicationDetails({ application }: { application: Application }) {
    const values = [
        ['Status', statusLabels[application.status]],
        ['Organisation type', application.organisation_type],
        ['Industry', application.industry],
        ['City / region', [application.city, application.state_or_region].filter(Boolean).join(' · ')],
        ['Representative role', application.representative_role],
        ['Registration number', application.registration_number],
        ['Registering authority', application.registering_authority],
        ['Date registered', application.registered_on],
        ['Official contact email', application.official_contact_email],
        ['Registered office address', application.registered_office_address],
        ['Website', application.website],
        ['Application reference', application.id],
        ['Submitted', application.created_at],
        ['Decision recorded', application.reviewed_at],
        ['Note to applicant', application.review_note],
        ['Approval email sent', application.approval_email_sent_at],
    ];

    return (
        <div className="grid gap-4">
            <Button variant="outline" className="w-fit" asChild><a href={`${registrationUrl}/${application.id}/print`} target="_blank" rel="noreferrer">Print application / Save as PDF</a></Button>
            <dl className="grid gap-3 sm:grid-cols-2">
                {values.filter(([, value]) => Boolean(value)).map(([label, value]) => (
                    <div key={label} className="min-w-0">
                        <dt className="text-xs text-muted-foreground">{label}</dt>
                        <dd className="whitespace-pre-wrap break-words text-sm">{value}</dd>
                    </div>
                ))}
            </dl>
            {application.has_certificate ? (
                <Button variant="outline" className="w-fit" asChild>
                    <a href={`${registrationUrl}/${application.id}/certificate`}>Download registration certificate</a>
                </Button>
            ) : <p className="text-sm text-muted-foreground">No registration certificate on file.</p>}
        </div>
    );
}

function Pagination({ applications }: { applications: ApplicationPage }) {
    return (
        <nav className="flex flex-wrap items-center gap-2" aria-label="Application pages">
            <span className="mr-2 text-sm text-muted-foreground">
                {applications.total} {applications.total === 1 ? 'application' : 'applications'} · Page {applications.current_page} of {applications.last_page}
            </span>
            {applications.prev_page_url && <Button variant="outline" asChild><Link href={applications.prev_page_url}>Previous</Link></Button>}
            {applications.next_page_url && <Button variant="outline" asChild><Link href={applications.next_page_url}>Next</Link></Button>}
        </nav>
    );
}

function ConfirmDecision({ application, mode, suffix, onClose }: {
    application: Application;
    mode: 'approved' | 'declined';
    suffix: string;
    onClose: () => void;
}) {
    const form = useForm({ status: mode, review_note: '' });
    const rejecting = mode === 'declined';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(`${registrationUrl}/${application.id}/review${suffix}`, {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (errors) => {
                if (errors.approvalEmail) onClose();
            },
        });
    }

    return (
        <form className="grid gap-4" onSubmit={submit}>
            <DialogHeader>
                <DialogTitle>{rejecting ? 'Reject application' : 'Approve application'}</DialogTitle>
                <DialogDescription>
                    {rejecting
                        ? `Reject ${application.name}? The decision and your reason will remain in the application history. The applicant can submit a new application.`
                        : `Approve ${application.name}? This opens the association workspace for the applicant and sends confirmation to the official contact email if one is on file.`}
                </DialogDescription>
            </DialogHeader>
            <label className={field}>
                {rejecting ? 'Reason for rejection' : 'Note to applicant (optional)'}
                <textarea className={input} rows={4} maxLength={2000} required={rejecting} value={form.data.review_note} onChange={(event) => form.setData('review_note', event.target.value)} />
            </label>
            {Object.values(form.errors).map((error, index) => <p key={index} role="alert" className="text-sm text-destructive">{error}</p>)}
            <DialogFooter>
                <Button type="button" variant="outline" disabled={form.processing} onClick={onClose}>Cancel</Button>
                <Button type="submit" variant={rejecting ? 'destructive' : 'default'} disabled={form.processing}>
                    {rejecting ? 'Confirm rejection' : 'Confirm approval'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function ReviewQueue({ applications, filters, status }: {
    applications: ApplicationPage;
    filters: QueueFilters;
    status: Application['status'] | null;
}) {
    const [expanded, setExpanded] = useState<string | null>(null);
    const [dialog, setDialog] = useState<{ id: string; mode: DialogMode } | null>(null);
    const opener = useRef<HTMLButtonElement>(null);
    const selected = applications.data.find(({ id }) => id === dialog?.id);
    const queueUrl = `${registrationUrl}/review`;
    const params = new URLSearchParams({ ...filters, ...(status ? { status } : {}) });
    if (!filters.q) params.delete('q');
    const suffix = `?${params}`;
    const columns = [
        ['name', 'Association'],
        ['industry', 'Industry'],
        ['status', 'Status'],
        ['created_at', 'Submitted'],
    ] as const;

    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const q = String(new FormData(event.currentTarget).get('q') ?? '').trim();
        router.get(queueUrl, { ...Object.fromEntries(params), q });
    }

    function openDialog(application: Application, mode: DialogMode, button: HTMLButtonElement) {
        if (!dialog) opener.current = button;
        setDialog({ id: application.id, mode });
    }

    function actions(application: Application, includeReview = true, includeEmail = false) {
        return (
            <div className="flex flex-wrap gap-2">
                {includeReview && <Button type="button" size="sm" variant="outline" onClick={(event) => openDialog(application, 'details', event.currentTarget)}>Review</Button>}
                {application.status === 'pending' && <>
                    <Button type="button" size="sm" onClick={(event) => openDialog(application, 'approved', event.currentTarget)}>Approve</Button>
                    <Button type="button" size="sm" variant="destructive" onClick={(event) => openDialog(application, 'declined', event.currentTarget)}>Reject</Button>
                </>}
                {includeEmail && application.status === 'approved' && application.official_contact_email && !application.approval_email_sent_at && (
                    <Button size="sm" variant="outline" asChild>
                        <Link as="button" method="post" href={`${registrationUrl}/${application.id}/approval-email${suffix}`} onSuccess={() => setDialog(null)}>Send approval email</Link>
                    </Button>
                )}
            </div>
        );
    }

    return (
        <section className="grid min-w-0 gap-4" aria-label="Association applications">
            <nav className="flex flex-wrap gap-2" aria-label="Filter registrations by status">
                {(['all', 'pending', 'approved', 'declined'] as const).map((value) => {
                    const target = new URLSearchParams(params);
                    if (value === 'all') target.delete('status');
                    else target.set('status', value);
                    const active = value === (status ?? 'all');
                    return (
                        <Button key={value} variant={active ? 'default' : 'outline'} asChild>
                            <Link href={`${queueUrl}?${target}`} aria-current={active ? 'page' : undefined}>
                                {value === 'all' ? 'All registrations' : statusLabels[value]}
                            </Link>
                        </Button>
                    );
                })}
            </nav>
            <form className="flex flex-wrap items-end gap-2" onSubmit={search}>
                <label className={`${field} w-full sm:max-w-sm`}>
                    Search applications
                    <input className={input} name="q" type="search" maxLength={120} defaultValue={filters.q} placeholder="Name, registration number, email or reference" />
                </label>
                <Button type="submit" variant="outline">Search</Button>
                {filters.q && <Button variant="ghost" asChild>
                    <Link href={`${queueUrl}?${new URLSearchParams({ ...Object.fromEntries(params), q: '' })}`}>Clear search</Link>
                </Button>}
            </form>
            <div className="overflow-x-auto rounded-xl border bg-card">
                <table className="w-full min-w-[48rem] text-left text-sm">
                    <thead className="border-b bg-muted/40">
                        <tr>
                            {columns.map(([column, label]) => {
                                const active = filters.sort === column;
                                const next = active && filters.direction === 'asc' ? 'desc' : 'asc';
                                const target = new URLSearchParams({ ...Object.fromEntries(params), sort: column, direction: next });
                                return (
                                    <th key={column} scope="col" className="p-3" aria-sort={active ? (filters.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                                        <Link className="inline-flex items-center gap-2 hover:underline" href={`${queueUrl}?${target}`}>
                                            {label}<span aria-hidden="true">{active ? (filters.direction === 'asc' ? '↑' : '↓') : '↕'}</span>
                                        </Link>
                                    </th>
                                );
                            })}
                            <th scope="col" className="p-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {applications.data.map((application) => {
                            const open = expanded === application.id;
                            return (
                                <Fragment key={application.id}>
                                    <tr className="border-b">
                                        <th scope="row" className="p-3 font-medium">
                                            <button type="button" className="text-left hover:underline" aria-expanded={open} aria-controls={open ? `details-${application.id}` : undefined} onClick={() => setExpanded(open ? null : application.id)}>
                                                <span aria-hidden="true" className="mr-2">{open ? '▾' : '▸'}</span>{application.name}
                                            </button>
                                        </th>
                                        <td className="p-3">{application.industry}</td>
                                        <td className="p-3"><span className="rounded-full bg-muted px-3 py-1">{statusLabels[application.status]}</span></td>
                                        <td className="p-3 tabular-nums">{application.created_at}</td>
                                        <td className="p-3">{actions(application)}</td>
                                    </tr>
                                    {open && <tr id={`details-${application.id}`} className="border-b bg-background/40">
                                        <td colSpan={5} className="p-4">
                                            <div className="grid gap-4">
                                                <ApplicationDetails application={application} />
                                                {actions(application, true, true)}
                                            </div>
                                        </td>
                                    </tr>}
                                </Fragment>
                            );
                        })}
                        {applications.data.length === 0 && <tr><td colSpan={5} className="p-4 text-muted-foreground">No registrations match this view.</td></tr>}
                    </tbody>
                </table>
            </div>
            <Pagination applications={applications} />
            <Dialog open={Boolean(selected && dialog)} onOpenChange={(open) => { if (!open) setDialog(null); }}>
                {selected && dialog && <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-2xl" onCloseAutoFocus={(event) => {
                    if (opener.current?.isConnected) {
                        event.preventDefault();
                        opener.current.focus();
                    }
                }}>
                    {dialog.mode === 'details' ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>{selected.name}</DialogTitle>
                                <DialogDescription>Association registration details</DialogDescription>
                            </DialogHeader>
                            <ApplicationDetails application={selected} />
                            {actions(selected, false, true)}
                        </>
                    ) : (
                        <ConfirmDecision key={dialog.mode} application={selected} mode={dialog.mode} suffix={suffix} onClose={() => setDialog(null)} />
                    )}
                </DialogContent>}
            </Dialog>
        </section>
    );
}

export default function AssociationRegistration({
    applications, reviewing, canReview, types, industries, statusFilter, queueFilters,
}: {
    applications: ApplicationPage;
    reviewing: boolean;
    statusFilter: keyof typeof statusLabels | null;
    queueFilters?: QueueFilters;
    canReview: boolean;
    types: string[];
    industries: string[];
}) {
    const { filmunityNavigation, errors } = usePage<{
        filmunityNavigation?: { canApplyForAssociation: boolean };
        errors: Record<string, string>;
    }>().props;
    const canApply = filmunityNavigation?.canApplyForAssociation === true;
    const heading = reviewing ? 'Association review queue' : 'Association registration';

    return (
        <main className="grid w-full min-w-0 gap-5 p-4">
            <Head title={heading} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{heading}</h1>
                <div className="flex flex-wrap gap-2">
                    {canReview && <Button variant="outline" asChild>
                        <Link href="/filmunity-admin">FILMUNITY Admin</Link>
                    </Button>}
                    <Button variant="outline" asChild><Link href="/dashboard?view=member">Dashboard</Link></Button>
                </div>
            </div>
            {errors.approvalEmail && <p role="alert" className="text-sm text-destructive">{errors.approvalEmail}</p>}
            {!reviewing && (canApply
                ? <RegistrationForm types={types} industries={industries} />
                : <p className="text-sm text-muted-foreground">You can submit another application only after your current application is declined.</p>)}

            {reviewing && queueFilters ? (
                <ReviewQueue key={JSON.stringify([queueFilters, statusFilter, applications.current_page])} applications={applications} filters={queueFilters} status={statusFilter} />
            ) : !reviewing && (
                <section aria-label="My registrations" className="grid gap-3">
                    <h2 className="font-semibold">My registrations</h2>
                    {applications.data.length === 0 && <p className="text-sm text-muted-foreground">No registrations to display.</p>}
                    {applications.data.map((application) => (
                        <article key={application.id} className={card}>
                            <div className="flex flex-wrap justify-between gap-2">
                                <h3 className="break-words font-semibold">{application.name}</h3>
                                <span className="rounded-full bg-muted px-3 py-1 text-sm">{statusLabels[application.status]}</span>
                            </div>
                            <ApplicationDetails application={application} />
                            {application.status === 'approved' && (
                                <Button className="w-fit" asChild>
                                    <Link href={`/association-workspaces/${application.id}`}>Open association workspace</Link>
                                </Button>
                            )}
                        </article>
                    ))}
                    <Pagination applications={applications} />
                </section>
            )}
        </main>
    );
}

AssociationRegistration.layout = {
    breadcrumbs: [{ title: 'Association registration', href: registrationUrl }],
};
