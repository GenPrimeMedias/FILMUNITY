import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/searchable-select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    ProtectedFields,
    WorkspaceEditProtection,
} from '@/components/workspace-edit-protection';

type Row = Record<string, any>;
type Props = {
    associationId: string;
    section: string;
    bodies: Row[];
    positions: Row[];
    policies: Row[];
    terms: Row[];
    appointments: Row[];
    meetings: Row[];
    participants: Row[];
    agendaItems: Row[];
    votes: Row[];
    reviewers: Row[];
    governanceSettings: Row | null;
    minutes: Row[];
    actions: Row[];
    members: Row[];
    summary: Record<string, number>;
    selectedTermId?: string;
    selectedMeetingId?: string;
    selectedAgendaItemId?: string;
};
const card =
    'grid gap-4 rounded-xl border border-t-4 border-t-primary bg-card p-5';
const input = 'rounded-lg border bg-background px-3 py-2';
const label = 'grid gap-1 text-sm font-medium';

export default function BoardManagement(props: Props) {
    const section = props.section;
    const nav = [
        [
            'overview',
            'Board overview',
            `/association-board/${props.associationId}`,
        ],
        [
            'setup',
            'Governance setup',
            `/association-board/${props.associationId}/setup`,
        ],
        [
            'terms',
            'Positions and terms',
            `/association-board/${props.associationId}/terms?view=positions`,
        ],
        [
            'members',
            'Board members',
            `/association-board/${props.associationId}/terms?view=members`,
        ],
        [
            'directory',
            'Board directory',
            `/association-board/${props.associationId}/directory`,
        ],
        [
            'meetings',
            'Board meetings',
            `/association-board/${props.associationId}/meetings`,
        ],
        [
            'minutes',
            'Minutes and resolutions',
            `/association-board/${props.associationId}/minutes`,
        ],
        [
            'actions',
            'Action tracker',
            `/association-board/${props.associationId}/actions`,
        ],
    ] as const;
    return (
        <WorkspaceEditProtection
            association={props.associationId}
            resource="board"
            dirty={false}
        >
            <Head title={`Board Management · ${section}`} />
            <main className="grid w-full max-w-none min-w-0 gap-5 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-sm text-muted-foreground">
                            Association governance workspace
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Board Management
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                            Configurable governance records based on the
                            association’s reviewed governing documents. This
                            module does not grant software permissions or
                            certify legal compliance.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link
                            href={`/association-workspaces/${props.associationId}`}
                        >
                            Association workspace
                        </Link>
                    </Button>
                </header>
                <nav
                    className="flex flex-wrap gap-2"
                    aria-label="Board management sections"
                >
                    {nav.map(([key, title, href]) => (
                        <Button
                            key={key}
                            asChild
                            variant={section === key ? 'default' : 'outline'}
                        >
                            <Link
                                href={href}
                                aria-current={
                                    section === key ? 'page' : undefined
                                }
                            >
                                {title}
                            </Link>
                        </Button>
                    ))}
                </nav>
                {section === 'overview' && <Overview {...props} />}
                <ProtectedFields>
                    {section === 'setup' && <Setup {...props} />}
                    {(section === 'terms' || section === 'members') && (
                        <Terms {...props} />
                    )}
                    {section === 'meetings' && <Meetings {...props} />}
                    {section === 'directory' && <DirectoryV2 {...props} />}
                    {section === 'minutes' && <Minutes {...props} />}
                    {section === 'actions' && <Actions {...props} />}
                </ProtectedFields>
            </main>
        </WorkspaceEditProtection>
    );
}

function Directory({ appointments }: Props) {
    return <section className={card}><h2 className="font-semibold">Board directory</h2><p className="text-sm text-muted-foreground">Private association view. Publication is disabled unless explicitly enabled in governance settings.</p><div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{appointments.map((item) => <article className="rounded-xl border p-4" key={item.id}><div className="mb-3 flex items-center gap-3"><div className="flex h-14 w-14 items-center justify-center overflow-hidden rounded-full border bg-muted text-lg font-semibold">{item.portrait_path ? <img className="h-full w-full object-cover" src={`/association-board/${item.association_id}/appointments/${item.id}/portrait`} alt="" /> : (item.member_name ?? item.external_name ?? 'V').slice(0, 1).toUpperCase()}</div><div><h3 className="font-semibold">{item.member_name ?? item.external_name ?? 'Vacant'}</h3><p className="text-sm text-muted-foreground">{item.position_title}</p></div></div><p className="text-sm">{item.status} · {item.starts_on} — {item.ends_on ?? 'Open'}</p></article>)}</div></section>;
}

function DirectoryV2({ associationId, terms, positions, appointments }: Props) {
    const initialTerm = terms.find((term) => term.state === 'active') ?? terms[0];
    const [termId, setTermId] = useState(initialTerm?.id ?? '');
    const [audience, setAudience] = useState<'internal' | 'public'>('internal');
    const term = terms.find((item) => item.id === termId);
    const termPositions = positions.filter((position) => position.body_id === term?.body_id).sort((a, b) => Number(a.display_order ?? 0) - Number(b.display_order ?? 0));
    const termAppointments = appointments.filter((item) => item.term_id === termId && ['scheduled', 'serving'].includes(item.status));
    const rows = termPositions.flatMap((position) => Array.from({ length: Number(position.seats) }, (_, index) => index + 1).map((seat) => ({ position, seat, appointment: termAppointments.find((item) => item.position_id === position.id && Number(item.seat_number) === seat) })));
    const officeBearers = rows.filter((row) => row.position.office_bearer);
    const committeeMembers = rows.filter((row) => !row.position.office_bearer);
    const exportCsv = () => {
        const values = rows.map(({ position, seat, appointment }) => [
            `${position.title} - Seat ${seat} of ${position.seats}`,
            appointment ? 'Filled' : 'Vacant',
            appointment?.member_name ?? appointment?.external_name ?? '',
            audience === 'internal' ? appointment?.member_email ?? '' : '',
            audience === 'internal' ? appointment?.appointment_method ?? '' : '',
            audience === 'internal' ? appointment?.starts_on ?? '' : '',
            audience === 'internal' ? appointment?.ends_on ?? '' : '',
        ]);
        const csv = [['Position', 'Status', 'Member', 'Email', 'Method', 'Starts', 'Ends'], ...values].map((line) => line.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(',')).join('\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `board-roster-${term?.name ?? 'term'}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    };
    const renderCard = ({ position, seat, appointment }: (typeof rows)[number], featured = false) => (
        <article className={`rounded-xl border p-4 ${appointment ? '' : 'border-dashed opacity-75'} ${featured ? 'bg-primary/5' : ''}`} key={`${position.id}-${seat}`}>
            <div className="flex items-start gap-3"><div className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full border bg-muted text-lg font-semibold">{appointment?.portrait_path ? <img className="h-full w-full object-cover" src={`/association-board/${associationId}/appointments/${appointment.id}/portrait`} alt="" /> : (appointment?.member_name ?? appointment?.external_name ?? position.title).slice(0, 1).toUpperCase()}</div><div className="min-w-0"><h3 className="font-semibold">{appointment?.member_name ?? appointment?.external_name ?? `${position.title} - Vacant`}</h3><p className="text-sm text-muted-foreground">{position.title} - Seat {seat} of {position.seats}</p><div className="mt-2 flex flex-wrap gap-2 text-xs">{position.office_bearer ? <span className="rounded-full bg-primary/15 px-2 py-1 text-primary">Office bearer</span> : null}{position.voting_eligible ? <span className="rounded-full bg-muted px-2 py-1 text-muted-foreground">Voting</span> : <span className="rounded-full bg-muted px-2 py-1 text-muted-foreground">Non-voting</span>}</div></div></div>
            {appointment ? <div className="mt-3 grid gap-1 text-sm text-muted-foreground">{audience === 'internal' && appointment.member_email ? <span>{appointment.member_email}</span> : null}<span>{audience === 'internal' ? `${appointment.starts_on} - ${appointment.ends_on ?? 'Open'} - ${appointment.appointment_method}` : 'Representative in this term'}</span></div> : <p className="mt-3 text-sm text-muted-foreground">Vacant seat{audience === 'internal' ? <> - <a className="underline" href={`/association-board/${associationId}/terms?view=members`}>Fill from Board members</a></> : null}</p>}
        </article>
    );
    return <section className={`${card} w-full`} data-board-directory>
        <div data-directory-header className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="font-semibold">Board directory</h2><p className="text-sm text-muted-foreground">Review current and historical board representation with audience-appropriate privacy.</p></div><div className="flex flex-wrap gap-2" data-directory-actions><Button type="button" variant="outline" onClick={exportCsv}>Export CSV</Button><Button type="button" variant="outline" onClick={() => window.print()}>Print / PDF</Button></div></div>
        <div className="grid gap-3 rounded-lg border p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]" data-directory-controls><label className={label}>Term selector<SearchableSelect className={input} value={termId} options={terms.map((item) => ({ value: item.id, label: `${item.name} - ${item.state}`, keywords: item.body_name }))} onValueChange={setTermId} /></label><div className="grid gap-1 text-sm font-medium"><span>View / audience</span><div className="flex rounded-lg border p-1"><button type="button" className={`flex-1 rounded px-3 py-2 ${audience === 'internal' ? 'bg-primary text-primary-foreground' : ''}`} onClick={() => setAudience('internal')}>Internal Board View</button><button type="button" className={`flex-1 rounded px-3 py-2 ${audience === 'public' ? 'bg-primary text-primary-foreground' : ''}`} onClick={() => setAudience('public')}>Member / Public Preview</button></div></div><div className="flex items-end text-sm text-muted-foreground">{rows.filter((row) => row.appointment).length} filled - {rows.filter((row) => !row.appointment).length} vacant</div></div>
        <div data-directory-cards className="grid gap-5">
            <div className="grid gap-3"><h3 className="font-semibold">Office bearers</h3><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{officeBearers.length ? officeBearers.map((row) => renderCard(row, true)) : <p className="text-sm text-muted-foreground">No office bearers configured for this term.</p>}</div></div>
            <div className="grid gap-3"><h3 className="font-semibold">Executive &amp; committee members</h3><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{committeeMembers.length ? committeeMembers.map((row) => renderCard(row)) : <p className="text-sm text-muted-foreground">No additional seats configured for this term.</p>}</div></div>
        </div>
        <div data-directory-print-table className="hidden">
            <table className="w-full text-left text-sm">
                <thead>
                    <tr><th colSpan={audience === 'internal' ? 7 : 5} className="text-left text-xl">Board directory · {term?.name ?? 'Selected term'} · {audience === 'internal' ? 'Internal Board View' : 'Member / Public Directory Preview'}</th></tr>
                    <tr><th>Position</th><th>Status</th><th>Member</th><th>Start date</th><th>End date</th>{audience === 'internal' ? <><th>Contact</th><th>Method</th></> : null}</tr>
                </thead>
                <tbody>{rows.map(({ position, seat, appointment }) => <tr key={`print-${position.id}-${seat}`}><td>{position.title} · Seat {seat} of {position.seats}</td><td>{appointment ? 'Filled' : 'Vacant'}</td><td>{appointment?.member_name ?? appointment?.external_name ?? 'Vacant seat'}</td><td>{appointment?.starts_on ?? '—'}</td><td>{appointment?.ends_on ?? 'Open'}</td>{audience === 'internal' ? <><td>{appointment?.member_email ?? '—'}</td><td>{appointment?.appointment_method ?? '—'}</td></> : null}</tr>)}</tbody>
            </table>
        </div>
    </section>;
}

function Overview({
    associationId,
    summary,
    bodies,
    positions,
    policies,
    terms,
    appointments,
    meetings,
    actions,
    minutes,
    agendaItems,
}: Props) {
    const activeTerm = terms.find((term) => term.state === 'active');
    const activePositions = positions.filter((position) => position.body_id === activeTerm?.body_id);
    const activeAppointments = appointments.filter((appointment) => appointment.term_id === activeTerm?.id && ['scheduled', 'serving'].includes(appointment.status));
    const totalSeats = activePositions.reduce((total, position) => total + Number(position.seats), 0);
    const filledSeats = activeAppointments.length;
    const vacantPosition = activePositions.find((position) => Number(position.seats) > activeAppointments.filter((appointment) => appointment.position_id === position.id).length);
    const policy = policies.filter((item) => item.body_id === activeTerm?.body_id && item.status === 'reviewed').sort((a, b) => `${b.effective_from}-${b.version}`.localeCompare(`${a.effective_from}-${a.version}`))[0];
    const now = new Date();
    const upcoming = meetings.filter((meeting) => new Date(meeting.starts_at) >= now && !['cancelled', 'closed'].includes(meeting.state)).sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());
    const nextMeeting = upcoming[0];
    const overdueActions = actions.filter((action) => action.due_on && action.due_on < new Date().toISOString().slice(0, 10) && !['completed', 'cancelled'].includes(action.status));
    const openActions = actions.filter((action) => ['open', 'in_progress', 'blocked'].includes(action.status));
    const latestDecisions = agendaItems.filter((item) => ['carried', 'rejected', 'deferred', 'tie'].includes(item.outcome)).sort((a, b) => String(b.updated_at ?? '').localeCompare(String(a.updated_at ?? ''))).slice(0, 3);
    const meetingFor = (meetingId: string) => meetings.find((meeting) => meeting.id === meetingId);
    const meetingDate = (meetingId: string) => { const source = meetingFor(meetingId); return source?.starts_at ? new Date(source.starts_at).toLocaleDateString() : ''; };
    const setupIncomplete = !activeTerm || filledSeats === 0;
    const bodyReady = bodies.length > 0 && activePositions.length > 0 && Boolean(policy);
    const alertItems = [
        totalSeats - filledSeats > 0 ? { tone: 'amber', text: `${totalSeats - filledSeats} seat${totalSeats - filledSeats === 1 ? '' : 's'} currently vacant${vacantPosition ? ` (${vacantPosition.title})` : ''} — affects eligible quorum count.` } : null,
        minutes.some((item) => item.state === 'draft') ? { tone: 'amber', text: 'Minutes are still in Draft and awaiting approval lock.' } : null,
        overdueActions.length ? { tone: 'red', text: `${overdueActions.length} action item${overdueActions.length === 1 ? '' : 's'} overdue.` } : null,
    ].filter(Boolean) as { tone: string; text: string }[];
    return (
        <div className="grid gap-5">
            <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {[
                    ['Active term & governance', activeTerm?.name ?? 'No active term', policy ? `Policy v${policy.version} · Quorum: ${policy.quorum_type === 'percentage' ? `${policy.quorum_percent}%+1` : `${policy.quorum_count ?? '—'} count`}` : 'Governance policy not reviewed', `/association-board/${associationId}/terms?view=positions`, 'Manage terms →'],
                    ['Board roster & seats', activeTerm ? `${filledSeats} / ${totalSeats} Filled` : '0 / 0 Filled', vacantPosition ? `${totalSeats - filledSeats} vacant seat${totalSeats - filledSeats === 1 ? '' : 's'} (${vacantPosition.title})` : 'No vacant seats', `/association-board/${associationId}/terms?view=members${activeTerm ? `&term=${activeTerm.id}` : ''}`, 'Fill vacancy →'],
                    ['Next board meeting', nextMeeting ? new Date(nextMeeting.starts_at).toLocaleDateString() : 'None scheduled', nextMeeting ? `${nextMeeting.title} · ${nextMeeting.mode}` : 'Schedule the first meeting', `/association-board/${associationId}/meetings`, 'Open workspace →'],
                    ['Pending governance', `${openActions.length} Open Actions`, `${minutes.filter((item) => item.state === 'draft').length} Draft minute · ${overdueActions.length} Overdue`, `/association-board/${associationId}/actions`, 'Review items →'],
                ].map(([title, value, context, href, action]) => <Link className={`${card} transition-colors hover:border-primary`} href={href} key={title}><span className="text-sm text-muted-foreground">{title}</span><strong className="text-xl">{value}</strong><span className="text-sm text-muted-foreground">{context}</span><span className="text-sm font-semibold text-primary">{action}</span></Link>)}
            </section>
            <section className={card}>
                {setupIncomplete ? <><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-semibold">Setup progress</h2><p className="text-sm text-muted-foreground">Complete the core governance steps before operating the board.</p></div><Button asChild><Link href={`/association-board/${associationId}/${bodyReady ? 'terms?view=positions' : 'setup'}`}>Continue setup →</Link></Button></div><div className="grid gap-3 md:grid-cols-4">{[['1', 'Define body & policy', bodyReady], ['2', 'Activate term', Boolean(activeTerm)], ['3', 'Appoint board members', filledSeats > 0], ['4', 'Schedule first meeting', meetings.length > 0]].map(([number, text, complete]) => <div className="flex items-center gap-2 text-sm" key={String(number)}><span className={`flex h-7 w-7 items-center justify-center rounded-full ${complete ? 'bg-primary text-primary-foreground' : 'border'}`}>{complete ? '✓' : number}</span><span>{text}</span></div>)}</div></> : <><h2 className="font-semibold">Governance alerts</h2>{alertItems.length ? <div className="grid gap-2">{alertItems.map((alert) => <div className={`rounded-lg border p-3 text-sm ${alert.tone === 'red' ? 'border-red-500/40 bg-red-500/10 text-red-700' : 'border-amber-500/40 bg-amber-500/10 text-amber-800'}`} key={alert.text}>{alert.text}</div>)}</div> : <p className="text-sm text-muted-foreground">No governance alerts. The board is up to date.</p>}</>}
            </section>
            <div className="grid gap-5 lg:grid-cols-[3fr_2fr]">
                <section className={card}>
                    <h2 className="font-semibold">Upcoming meetings &amp; recent decisions</h2>
                    {nextMeeting ? <div className="rounded-lg border p-3"><div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-semibold">{nextMeeting.title}</h3><p className="text-sm text-muted-foreground">{new Date(nextMeeting.starts_at).toLocaleString()} · {nextMeeting.mode}</p><p className="text-sm">Required quorum: {nextMeeting.quorum_required ?? '—'}</p></div><Button asChild size="sm"><Link href={`/association-board/${associationId}/meetings`}>Take attendance</Link></Button></div></div> : <Empty text="No upcoming meetings scheduled." />}
                    <div className="mt-4 grid gap-2 border-t pt-4"><h3 className="text-sm font-semibold">Latest approved resolutions</h3>{latestDecisions.length ? latestDecisions.map((item) => <div className="flex flex-wrap items-center justify-between gap-2 text-sm" key={item.id}><span><strong>{item.resolution_number ?? `RES-${item.sequence}`}</strong> · {item.subject}<span className="ml-2 text-muted-foreground">{meetingDate(item.meeting_id)}</span></span><span className="rounded-full bg-primary/15 px-2 py-1 text-xs font-semibold">{item.outcome === 'carried' ? 'Passed' : item.outcome === 'rejected' ? 'Rejected' : 'Tabled'}</span></div>) : <p className="text-sm text-muted-foreground">No finalized resolutions yet.</p>}</div>
                </section>
                <section className={card}>
                    <h2 className="font-semibold">Priority action items</h2>
                    <div className="grid gap-2">{openActions.slice(0, 4).map((action) => <div className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm" key={action.id}><div className="min-w-0"><p className="truncate font-medium">{action.description}</p><p className="text-xs text-muted-foreground">{action.assignee_name ?? 'Unassigned'} · {action.due_on ?? 'No due date'}</p></div><span className={`shrink-0 rounded-full px-2 py-1 text-xs ${action.due_on && action.due_on < new Date().toISOString().slice(0, 10) ? 'bg-amber-500/15 text-amber-700' : 'bg-muted'}`}>{action.priority === 'normal' ? 'Medium' : action.priority}</span></div>)}{!openActions.length ? <Empty text="No open action items." /> : null}</div>
                    <div className="mt-4 grid gap-2 border-t pt-4"><h3 className="text-sm font-semibold">Quick actions</h3><div className="grid gap-2"><Button asChild><Link href={`/association-board/${associationId}/meetings`}>+ Schedule meeting</Link></Button><Button asChild variant="outline"><Link href={`/association-board/${associationId}/terms?view=members${activeTerm ? `&term=${activeTerm.id}` : ''}`}>+ Record appointment</Link></Button><Button asChild variant="outline"><Link href={`/association-board/${associationId}/print/directory`}>Export board directory (PDF)</Link></Button></div></div>
                </section>
            </div>
        </div>
    );
}

function Setup({ associationId, bodies, positions, policies }: Props) {
    const body = bodies[0];
    const current = policies[0];
    const bodyTypeOptions = [
        { value: 'board', label: 'Board' },
        { value: 'executive_committee', label: 'Executive Committee' },
        { value: 'advisory_council', label: 'Advisory Council' },
    ];
    const currentBodyType = body?.body_type ?? 'board';
    const bodyTypeLabel = currentBodyType
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character: string) => character.toUpperCase());
    const bodyTypeOptionsWithCurrent = bodyTypeOptions.some(
        (option) => option.value === currentBodyType,
    )
        ? bodyTypeOptions
        : [
              {
                  value: currentBodyType,
                  label: bodyTypeLabel,
              },
              ...bodyTypeOptions,
          ];
    const [rows, setRows] = useState(
        (positions.filter((item) => item.body_id === body?.id).length
            ? positions.filter((item) => item.body_id === body?.id)
            : [
                  {
                      title: 'President',
                      display_order: 1,
                      seats: 1,
                      responsibilities: '',
                      office_bearer: true,
                      voting_eligible: true,
                  },
              ]
        ).map((item) => ({
            title: item.title,
            display_order: item.display_order ?? 1,
            seats: item.seats,
            responsibilities: item.responsibilities ?? '',
            office_bearer: Boolean(item.office_bearer),
            voting_eligible: item.voting_eligible !== false,
        })),
    );
    const form = useForm({
        body_name: body?.name ?? 'Governing Board',
        body_type: currentBodyType,
        positions: rows,
        policy: {
            effective_from:
                current?.effective_from ??
                new Date().toISOString().slice(0, 10),
            timezone: current?.timezone ?? 'Asia/Kolkata',
            quorum_type: current?.quorum_type ?? 'fixed_count',
            quorum_count: current?.quorum_count ?? 1,
            quorum_percent: current?.quorum_percent ?? '',
            quorum_denominator: current?.quorum_denominator ?? 'eligible_seats',
            rounding_policy: current?.rounding_policy ?? 'ceil',
            voting_rule: current?.voting_rule ?? 'ordinary_majority',
            majority_percent: current?.majority_percent ?? '',
            notice_days: current?.notice_days ?? 0,
            allow_manual_determination: Boolean(
                current?.allow_manual_determination,
            ),
            allow_external_appointees: Boolean(
                current?.allow_external_appointees,
            ),
            policy_reference: current?.policy_reference ?? '',
            default_term_duration: current?.default_term_duration ?? '',
            custom_term_months: current?.custom_term_months ?? '',
            max_consecutive_terms: current?.max_consecutive_terms ?? '',
            reviewed: current?.status === 'reviewed',
        },
    });
    const submitPositions = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ body_name: data.body_name, body_type: data.body_type, positions: rows }));
        form.post(`/association-board/${associationId}/positions`, {
            preserveScroll: true,
        });
    };
    const submitPolicy = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ body_name: data.body_name, policy: data.policy }));
        form.post(`/association-board/${associationId}/policy`, { preserveScroll: true });
    };
    return (
        <div className="grid gap-5">
            <form className="grid gap-5" onSubmit={submitPositions}>
            <section className={card}>
                <h2 className="font-semibold">Governing body</h2>
                <div className="grid gap-4 md:grid-cols-2">
                    <label className={label}>
                        Body name
                        <input
                            className={input}
                            value={form.data.body_name}
                            onChange={(e) =>
                                form.setData('body_name', e.target.value)
                            }
                        />
                    </label>
                    <label className={label}>
                        Body type
                        <select
                            className={input}
                            value={form.data.body_type}
                            onChange={(e) =>
                                form.setData('body_type', e.target.value)
                            }
                        >
                            {bodyTypeOptionsWithCurrent.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            </section>
            <section className={card}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="font-semibold">Positions</h2>
                        <p className="text-sm text-muted-foreground">
                            Titles, seat capacity and voting eligibility are
                            association-defined.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            setRows([
                                ...rows,
                                {
                                    title: '',
                                    display_order: rows.length + 1,
                                    seats: 1,
                                    responsibilities: '',
                                    office_bearer: false,
                                    voting_eligible: true,
                                },
                            ])
                        }
                    >
                        Add position
                    </Button>
                </div>
                <div className="max-h-[32rem] space-y-3 overflow-y-auto pr-2">
                    {rows.map((row, index) => (
                        <div
                            className="grid gap-3 rounded-lg border p-3 md:grid-cols-[5rem_minmax(0,1.1fr)_6.5rem_minmax(0,1.5fr)_auto]"
                            key={index}
                        >
                        <label className={label}>
                            Order
                            <input
                                className={input}
                                type="number"
                                min="1"
                                value={row.display_order}
                                onChange={(e) =>
                                    setRows(
                                        rows.map((item, i) =>
                                            i === index
                                                ? {
                                                      ...item,
                                                      display_order: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : item,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label className={label}>
                            Title
                            <input
                                className={input}
                                value={row.title}
                                onChange={(e) =>
                                    setRows(
                                        rows.map((item, i) =>
                                            i === index
                                                ? {
                                                      ...item,
                                                      title: e.target.value,
                                                  }
                                                : item,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label className={label}>
                            Seats
                            <input
                                className={input}
                                type="number"
                                min="1"
                                value={row.seats}
                                onChange={(e) =>
                                    setRows(
                                        rows.map((item, i) =>
                                            i === index
                                                ? {
                                                      ...item,
                                                      seats: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : item,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label className={label}>
                            Responsibilities
                            <input
                                className={input}
                                value={row.responsibilities}
                                onChange={(e) =>
                                    setRows(
                                        rows.map((item, i) =>
                                            i === index
                                                ? {
                                                      ...item,
                                                      responsibilities:
                                                          e.target.value,
                                                  }
                                                : item,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <div className="grid gap-2 pt-6 text-sm">
                            <label className="flex items-center gap-2 whitespace-nowrap">
                                <input type="checkbox" checked={row.office_bearer} onChange={(e) => setRows(rows.map((item, i) => i === index ? { ...item, office_bearer: e.target.checked } : item))} />
                                Office bearer
                            </label>
                            <label className="flex items-center gap-2 whitespace-nowrap">
                                <input type="checkbox" checked={row.voting_eligible} onChange={(e) => setRows(rows.map((item, i) => i === index ? { ...item, voting_eligible: e.target.checked } : item))} />
                                Voting member
                            </label>
                        </div>
                        </div>
                    ))}
                </div>
                <Button className="w-fit" disabled={form.processing} type="submit">Save governing body &amp; positions</Button>
            </section>
            </form>
            <form className="grid gap-5" onSubmit={submitPolicy}>
            <section className={card}>
                <h2 className="font-semibold">Reviewed policy</h2>
                <p className="text-sm text-muted-foreground">
                    A policy must be reviewed before the system can use it to
                    determine quorum or a motion outcome.
                </p>
                <div className="grid gap-4 md:grid-cols-3">
                    <label className={label}>
                        Effective from
                        <input
                            className={input}
                            type="date"
                            value={form.data.policy.effective_from}
                            onChange={(e) =>
                                form.setData('policy', {
                                    ...form.data.policy,
                                    effective_from: e.target.value,
                                })
                            }
                        />
                    </label>
                    <label className={label}>
                        Timezone
                        <input
                            className={input}
                            value={form.data.policy.timezone}
                            onChange={(e) =>
                                form.setData('policy', {
                                    ...form.data.policy,
                                    timezone: e.target.value,
                                })
                            }
                        />
                    </label>
                    <label className={label}>
                        Quorum type
                        <select
                            className={input}
                            value={form.data.policy.quorum_type}
                            onChange={(e) =>
                                form.setData('policy', {
                                    ...form.data.policy,
                                    quorum_type: e.target.value,
                                })
                            }
                        >
                            <option value="fixed_count">Fixed count</option>
                            <option value="percentage">Percentage</option>
                        </select>
                    </label>
                    {form.data.policy.quorum_type === 'fixed_count' ? (
                        <label className={label}>
                            Fixed quorum count
                            <input className={input} type="number" min="1" value={form.data.policy.quorum_count} onChange={(e) => form.setData('policy', { ...form.data.policy, quorum_count: Number(e.target.value) })} />
                        </label>
                    ) : (
                        <label className={label}>
                            Percentage quorum
                            <input className={input} type="number" min="1" max="100" value={form.data.policy.quorum_percent} onChange={(e) => form.setData('policy', { ...form.data.policy, quorum_percent: e.target.value })} />
                        </label>
                    )}
                    <label className={label}>
                        Denominator
                        <select
                            className={input}
                            value={form.data.policy.quorum_denominator}
                            onChange={(e) =>
                                form.setData('policy', {
                                    ...form.data.policy,
                                    quorum_denominator: e.target.value,
                                })
                            }
                        >
                            <option value="eligible_seats">
                                Eligible seats
                            </option>
                            <option value="serving_appointments">
                                Serving appointments
                            </option>
                            <option value="attending_invited">
                                Attending invited participants
                            </option>
                        </select>
                    </label>
                    <label className={label}>
                        Voting rule
                        <select
                            className={input}
                            value={form.data.policy.voting_rule}
                            onChange={(e) =>
                                form.setData('policy', {
                                    ...form.data.policy,
                                    voting_rule: e.target.value,
                                })
                            }
                        >
                            <option value="ordinary_majority">
                                Ordinary majority
                            </option>
                            <option value="percentage">
                                Specified percentage
                            </option>
                            <option value="unanimity">Unanimity</option>
                        </select>
                    </label>
                    <label className={label}>
                        Notice days
                        <input
                            className={input}
                            type="number"
                            min="0"
                            value={form.data.policy.notice_days}
                            onChange={(e) =>
                                form.setData('policy', {
                                    ...form.data.policy,
                                    notice_days: Number(e.target.value),
                                })
                            }
                        />
                    </label>
                    <label className={label}>
                        Default term duration
                        <select className={input} value={form.data.policy.default_term_duration} onChange={(e) => form.setData('policy', { ...form.data.policy, default_term_duration: e.target.value })}>
                            <option value="">Not configured</option>
                            <option value="1_year">1 Year</option>
                            <option value="2_years">2 Years</option>
                            <option value="3_years">3 Years</option>
                            <option value="custom">Custom</option>
                        </select>
                    </label>
                    {form.data.policy.default_term_duration === 'custom' && <label className={label}>
                        Custom term duration (months)
                        <input className={input} type="number" min="1" max="120" value={form.data.policy.custom_term_months} onChange={(e) => form.setData('policy', { ...form.data.policy, custom_term_months: Number(e.target.value) })} />
                    </label>}
                    <label className={label}>
                        Max consecutive terms
                        <select className={input} value={form.data.policy.max_consecutive_terms} onChange={(e) => form.setData('policy', { ...form.data.policy, max_consecutive_terms: e.target.value === '' ? '' : Number(e.target.value) })}>
                            <option value="">Unlimited</option>
                            <option value="1">1 term</option>
                            <option value="2">2 terms</option>
                            <option value="3">3 terms</option>
                            <option value="4">4 terms</option>
                            <option value="5">5 terms</option>
                        </select>
                    </label>
                    <label className="flex items-center gap-2 pt-6 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.policy.reviewed}
                            onChange={(e) =>
                                form.setData('policy', {
                                    ...form.data.policy,
                                    reviewed: e.target.checked,
                                })
                            }
                        />{' '}
                        Reviewed configuration
                    </label>
                    <label className="flex items-center gap-2 pt-6 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.policy.allow_external_appointees}
                            onChange={(e) =>
                                form.setData('policy', {
                                    ...form.data.policy,
                                    allow_external_appointees: e.target.checked,
                                })
                            }
                        />{' '}
                        Permit external appointees
                    </label>
                </div>
                <label className={label}>
                    Policy reference
                    <input
                        className={input}
                        value={form.data.policy.policy_reference}
                        onChange={(e) =>
                            form.setData('policy', {
                                ...form.data.policy,
                                policy_reference: e.target.value,
                            })
                        }
                        placeholder="Approved governing document or review reference"
                    />
                </label>
                <Button disabled={form.processing} type="submit">
                    Save new policy version
                </Button>
            </section>
            </form>
        </div>
    );
}

function Terms({
    associationId,
    section,
    selectedTermId,
    bodies,
    terms,
    positions,
    policies,
    appointments,
    members,
}: Props) {
    const form = useForm({
        body_id: bodies[0]?.id ?? '',
        name: '',
        starts_on: '',
        ends_on: '',
        appointment_reference: '',
        policy_id: '',
    });
    const initialTerm = terms.find((term) => term.id === selectedTermId) ?? terms[0];
    const initialPosition = positions.find((position) => position.body_id === initialTerm?.body_id);
    const appointment = useForm({
        body_id: initialTerm?.body_id ?? bodies[0]?.id ?? '',
        term_id: initialTerm?.id ?? '',
        position_id: initialPosition?.id ?? '',
        member_id: '',
        external_name: '',
        appointment_method: 'appointed',
        seat_number: initialPosition ? 1 : 0,
        starts_on: initialTerm?.starts_on ?? '',
        ends_on: '',
    });
    const [appointmentSource, setAppointmentSource] = useState<'member' | 'external'>('member');
    const [endingAppointment, setEndingAppointment] = useState<Row | null>(null);
    const endForm = useForm({
        status: 'resigned',
        effective_on: new Date().toISOString().slice(0, 10),
        reason: 'Resigned',
    });
    const selectedTerm = terms.find((term) => term.id === appointment.data.term_id);
    const selectedPositions = positions.filter((position) => position.body_id === selectedTerm?.body_id);
    const selectedPosition = selectedPositions.find((position) => position.id === appointment.data.position_id);
    const activePolicy = policies
        .filter((policy) => policy.body_id === selectedTerm?.body_id && policy.status === 'reviewed')
        .filter((policy) => !selectedTerm?.starts_on || policy.effective_from <= selectedTerm.starts_on)
        .sort((a, b) => `${b.effective_from}-${b.version}`.localeCompare(`${a.effective_from}-${a.version}`))[0];
    const vacantSeats = selectedPosition
        ? Array.from({ length: Number(selectedPosition.seats) }, (_, index) => index + 1).filter(
              (seat) => !appointments.some(
                  (item) => item.term_id === selectedTerm?.id && item.position_id === selectedPosition.id && item.seat_number === seat && ['scheduled', 'serving'].includes(item.status),
              ),
          )
        : [];
    const addPolicyDuration = (date: string, policy: Row | undefined) => {
        if (!date || !policy?.default_term_duration) return '';
        const result = new Date(`${date}T00:00:00`);
        const months = policy.default_term_duration === 'custom'
            ? Number(policy.custom_term_months ?? 0)
            : Number.parseInt(policy.default_term_duration, 10) * 12;
        if (!months) return '';
        result.setMonth(result.getMonth() + months);
        result.setDate(result.getDate() - 1);
        return result.toISOString().slice(0, 10);
    };
    const chooseTerm = (termId: string) => {
        const term = terms.find((item) => item.id === termId);
        const termPositions = positions.filter((position) => position.body_id === term?.body_id);
        const firstPosition = termPositions[0];
        appointment.setData({
            ...appointment.data,
            body_id: term?.body_id ?? '',
            term_id: termId,
            position_id: firstPosition?.id ?? '',
            seat_number: firstPosition ? 1 : 0,
            starts_on: term?.starts_on ?? '',
            ends_on: '',
        });
    };
    const choosePosition = (positionId: string) => {
        const position = positions.find((item) => item.id === positionId);
        const available = position
            ? Array.from({ length: Number(position.seats) }, (_, index) => index + 1).filter(
                  (seat) => !appointments.some(
                      (item) => item.term_id === selectedTerm?.id && item.position_id === position.id && item.seat_number === seat && ['scheduled', 'serving'].includes(item.status),
                  ),
              )
            : [];
        appointment.setData({
            ...appointment.data,
            position_id: positionId,
            seat_number: available[0] ?? 0,
        });
    };
    useEffect(() => {
        if (!appointment.data.starts_on || appointment.data.ends_on) return;
        const end = addPolicyDuration(appointment.data.starts_on, activePolicy);
        if (end) appointment.setData('ends_on', end);
    }, [appointment.data.starts_on]);
    const changeTermState = (term: Row, state: string) => {
        if (state === 'active' && terms.some((item) => item.body_id === term.body_id && item.state === 'active' && item.id !== term.id)) {
            if (!window.confirm('Activating this term will mark the previous active term as Completed. Continue?')) return;
        }
        router.patch(`/association-board/${associationId}/terms/${term.id}`, { state }, { preserveScroll: true });
    };
    const filledSeats = (termId: string) => appointments.filter((item) => item.term_id === termId && ['scheduled', 'serving'].includes(item.status)).length;
    return (
        <div className={`grid gap-5 ${section === 'terms' ? 'lg:grid-cols-2' : ''}`}>
            {section === 'terms' ? (
            <>
            <section className={card}>
                <h2 className="font-semibold">Create a governing term</h2>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/association-board/${associationId}/terms`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className={label}>
                        Body
                        <SearchableSelect className={input} value={form.data.body_id} options={bodies.map((body) => ({ value: body.id, label: body.name, keywords: body.body_type }))} onValueChange={(value) => form.setData('body_id', value)} />
                    </label>
                    <label className={label}>
                        Term name
                        <input
                            className={input}
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className={label}>
                            Starts
                            <input
                                className={input}
                                type="date"
                                value={form.data.starts_on}
                                onChange={(e) => {
                                    const policy = policies
                                        .filter((item) => item.body_id === form.data.body_id && item.status === 'reviewed')
                                        .sort((a, b) => `${b.effective_from}-${b.version}`.localeCompare(`${a.effective_from}-${a.version}`))[0];
                                    form.setData({
                                        ...form.data,
                                        starts_on: e.target.value,
                                        ends_on: addPolicyDuration(e.target.value, policy),
                                        policy_id: policy?.id ?? '',
                                    });
                                }}
                            />
                        </label>
                        <label className={label}>
                            Ends
                            <input
                                className={input}
                                type="date"
                                value={form.data.ends_on}
                                onChange={(e) =>
                                    form.setData('ends_on', e.target.value)
                                }
                            />
                        </label>
                    </div>
                    <label className={label}>
                        Appointment reference
                        <input
                            className={input}
                            value={form.data.appointment_reference}
                            placeholder="e.g., AGM Election Minutes #2026-01 or Board Resolution Ref"
                            onChange={(e) =>
                                form.setData(
                                    'appointment_reference',
                                    e.target.value,
                                )
                            }
                        />
                    </label>
                    <Button type="submit">Create term</Button>
                </form>
                <div className="hidden">
                {terms.map((term) => (
                    <div className="border-t pt-3 text-sm" key={term.id}>
                        <strong>{term.name}</strong> · {term.body_name} ·{' '}
                        {term.state}
                        <div className="mt-2 flex flex-wrap gap-2">
                            {['scheduled', 'active', 'completed', 'cancelled']
                                .filter((state) => state !== term.state)
                                .map((state) => (
                                    <Button
                                        key={state}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.patch(
                                                `/association-board/${associationId}/terms/${term.id}`,
                                                { state },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {state}
                                    </Button>
                                ))}
                        </div>
                    </div>
                ))}
                </div>
            </section>
                <section className={`${card} w-full lg:col-span-2`}>
                    <div><h2 className="font-semibold">Governing terms directory</h2><p className="text-sm text-muted-foreground">Manage term lifecycle and hand off directly to the selected term roster.</p></div>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="border-b bg-muted/40"><tr><th className="px-3 py-3">Term name</th><th className="px-3 py-3">Duration</th><th className="px-3 py-3">Filled seats</th><th className="px-3 py-3">Status</th><th className="px-3 py-3 text-right">Lifecycle actions</th></tr></thead>
                            <tbody>{terms.map((term) => <tr className="border-b last:border-0" key={`directory-${term.id}`}><td className="px-3 py-3 font-medium">{term.name}<div className="text-xs text-muted-foreground">{term.body_name}</div></td><td className="px-3 py-3">{term.starts_on} - {term.ends_on}</td><td className="px-3 py-3">{filledSeats(term.id)} / {positions.filter((position) => position.body_id === term.body_id).reduce((total, position) => total + Number(position.seats), 0)} filled</td><td className="px-3 py-3"><span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold capitalize">{term.state}</span></td><td className="px-3 py-3"><div className="flex flex-wrap justify-end gap-2">{term.state === 'draft' ? <Button type="button" size="sm" variant="outline" onClick={() => changeTermState(term, 'scheduled')}>Schedule</Button> : null}{term.state === 'scheduled' ? <Button type="button" size="sm" variant="outline" onClick={() => changeTermState(term, 'active')}>Activate</Button> : null}{term.state === 'active' ? <Button type="button" size="sm" variant="outline" onClick={() => changeTermState(term, 'completed')}>Complete</Button> : null}{['draft', 'scheduled', 'active'].includes(term.state) ? <Button type="button" size="sm" variant="outline" onClick={() => changeTermState(term, 'cancelled')}>Cancel</Button> : null}<Button asChild type="button" size="sm"><Link href={`/association-board/${associationId}/terms?view=members&term=${term.id}`}>Manage roster</Link></Button></div></td></tr>)}</tbody>
                        </table>
                    </div>
            </section>
            </>
            ) : null}
            {section === 'members' ? (
            <>
            <section className={card}>
                <h2 className="font-semibold">Record an appointment</h2>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        appointment.post(
                            `/association-board/${associationId}/appointments`,
                            { preserveScroll: true },
                        );
                    }}
                >
                    <label className={label}>
                        Term
                        <SearchableSelect required className={input} value={appointment.data.term_id} options={terms.map((term) => ({ value: term.id, label: term.name, keywords: `${term.body_name} ${term.state}` }))} onValueChange={chooseTerm} />
                    </label>
                    <label className={label}>
                        Position
                        <SearchableSelect required className={input} value={appointment.data.position_id} options={selectedPositions.map((position) => ({ value: position.id, label: position.title, keywords: `${position.seats} seats` }))} onValueChange={choosePosition} />
                    </label>
                    <div className="grid gap-2">
                        <span className="text-sm font-medium">Appointee</span>
                        {activePolicy?.allow_external_appointees ? (
                            <div className="flex flex-wrap gap-4 text-sm">
                                <label className="flex items-center gap-2">
                                    <input type="radio" checked={appointmentSource === 'member'} onChange={() => { setAppointmentSource('member'); appointment.setData('external_name', ''); }} />
                                    Association member
                                </label>
                                <label className="flex items-center gap-2">
                                    <input type="radio" checked={appointmentSource === 'external'} onChange={() => { setAppointmentSource('external'); appointment.setData('member_id', ''); }} />
                                    External appointee
                                </label>
                            </div>
                        ) : null}
                    </div>
                    <label className={appointmentSource === 'member' ? label : 'hidden'}>
                        Association member
                        <SearchableSelect className={input} placeholder="Select member" value={appointment.data.member_id} options={members.map((member) => ({ value: String(member.id), label: `${member.name} · ${member.number}`, keywords: String(member.number) }))} onValueChange={(value) => appointment.setData('member_id', value)} />
                        <select
                            hidden
                            className={input}
                            value={appointment.data.member_id}
                            onChange={(e) =>
                                appointment.setData('member_id', e.target.value)
                            }
                        >
                            <option value="">Select member</option>
                            {members.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name} · {member.number}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className={activePolicy?.allow_external_appointees && appointmentSource === 'external' ? label : 'hidden'}>
                        External appointee
                        <input
                            className={input}
                            value={appointment.data.external_name}
                            onChange={(e) =>
                                appointment.setData(
                                    'external_name',
                                    e.target.value,
                                )
                            }
                        />
                    </label>
                    <div className="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)]">
                        <label className={`${label} min-w-0`}>
                            Seat
                            <select className={input} value={appointment.data.seat_number || ''} onChange={(e) => appointment.setData('seat_number', Number(e.target.value))}>
                                <option value="" disabled>Select vacant seat</option>
                                {vacantSeats.map((seat) => <option key={seat} value={seat}>Seat {seat}</option>)}
                            </select>
                        </label>
                        <label className={`${label} min-w-0`}>
                            Starts
                            <input
                                className={input}
                                type="date"
                                value={appointment.data.starts_on}
                                onChange={(e) =>
                                    appointment.setData(
                                        'starts_on',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <label className={`${label} min-w-0`}>
                            Method
                            <select className={`${input} w-full min-w-0`} value={appointment.data.appointment_method} onChange={(e) => appointment.setData('appointment_method', e.target.value)}>
                                <option value="elected">Elected (via AGM / Election)</option>
                                <option value="appointed">Appointed (by the Board / President)</option>
                                <option value="ex_officio">Ex-Officio (by virtue of another office)</option>
                                <option value="co_opted_interim">Co-opted / Interim (filling a mid-term vacancy)</option>
                            </select>
                        </label>
                    </div>
                    <Button type="submit">Record appointment</Button>
                </form>
                <div className="hidden">
                    {appointments.map((item) => (
                        <p className="text-sm" key={item.id}>
                            {item.position_title} ·{' '}
                            {item.member_name ?? item.external_name ?? 'Vacant'}{' '}
                            · {item.status}
                        </p>
                    ))}
                </div>
            </section>
        <section className={`${card} lg:col-span-2`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="font-semibold">Term roster &amp; vacancies</h2>
                    <p className="text-sm text-muted-foreground">
                        {selectedTerm ? `${selectedTerm.name} · ${selectedTerm.body_name}` : 'Select a term to view its seats.'}
                    </p>
                </div>
                <span className="text-sm text-muted-foreground">{selectedPositions.reduce((total, position) => total + Number(position.seats), 0)} total seats</span>
            </div>
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full min-w-[680px] text-left text-sm">
                    <thead className="border-b bg-muted/40">
                        <tr>
                            <th className="px-3 py-3 font-semibold">Position</th>
                            <th className="px-3 py-3 font-semibold">Status</th>
                            <th className="px-3 py-3 font-semibold">Member</th>
                            <th className="px-3 py-3 font-semibold">Start date</th>
                            <th className="px-3 py-3 text-right font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {selectedPositions.flatMap((position) => Array.from({ length: Number(position.seats) }, (_, index) => index + 1).map((seat) => {
                            const item = appointments.find((appointmentItem) => appointmentItem.term_id === selectedTerm?.id && appointmentItem.position_id === position.id && appointmentItem.seat_number === seat && ['scheduled', 'serving'].includes(appointmentItem.status));
                            return (
                                <tr className="border-b last:border-0" key={`${position.id}-${seat}`}>
                                    <td className="px-3 py-3 font-medium">{position.title} — Seat {seat} of {position.seats}</td>
                                    <td className="px-3 py-3"><span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${item ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'}`}>{item ? 'Filled' : 'Vacant'}</span></td>
                                    <td className="px-3 py-3">{item?.member_name ?? item?.external_name ?? '—'}</td>
                                    <td className="px-3 py-3">{item?.starts_on ?? '—'}</td>
                                    <td className="px-3 py-3 text-right">
                                        {item ? <Button type="button" size="sm" variant="outline" onClick={() => {
                                            const today = new Date().toISOString().slice(0, 10);
                                            endForm.setData({
                                                status: item.status === 'serving' ? 'resigned' : 'removed',
                                                effective_on: item.starts_on > today ? item.starts_on : today,
                                                reason: item.status === 'serving' ? 'Resigned' : 'Removed',
                                            });
                                            setEndingAppointment(item);
                                        }}>End / Resign</Button> : <Button type="button" size="sm" onClick={() => {
                                            appointment.setData({ ...appointment.data, body_id: selectedTerm?.body_id ?? '', term_id: selectedTerm?.id ?? '', position_id: position.id, seat_number: seat, starts_on: selectedTerm?.starts_on ?? '', ends_on: '' });
                                            setAppointmentSource('member');
                                        }}>Fill seat</Button>}
                                    </td>
                                </tr>
                            );
                        }))}
                        {!selectedPositions.length ? <tr><td className="px-3 py-4 text-muted-foreground" colSpan={5}>No positions are configured for this term.</td></tr> : null}
                    </tbody>
                </table>
            </div>
        </section>
        <Dialog open={Boolean(endingAppointment)} onOpenChange={(open) => { if (!open && !endForm.processing) setEndingAppointment(null); }}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>End appointment</DialogTitle>
                    <DialogDescription>
                        End this appointment while retaining the original record and tenure history.
                    </DialogDescription>
                </DialogHeader>
                <form className="grid gap-4" onSubmit={(event) => {
                    event.preventDefault();
                    if (!endingAppointment) return;
                    endForm.patch(`/association-board/${associationId}/appointments/${endingAppointment.id}`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            setEndingAppointment(null);
                            endForm.reset();
                        },
                    });
                }}>
                    <label className={label}>
                        End date
                        <input className={input} type="date" required min={endingAppointment?.starts_on ?? undefined} value={endForm.data.effective_on} onChange={(event) => endForm.setData('effective_on', event.target.value)} />
                    </label>
                    <label className={label}>
                        Reason
                        <select className={input} value={endForm.data.reason} onChange={(event) => endForm.setData({ reason: event.target.value, status: event.target.value === 'Resigned' ? 'resigned' : event.target.value === 'Term Completed' ? 'completed' : 'removed' })}>
                            <option value="Resigned">Resigned</option>
                            <option value="Term Completed">Term Completed</option>
                            <option value="Removed">Removed</option>
                        </select>
                    </label>
                    <Button disabled={endForm.processing} type="submit">Confirm end appointment</Button>
                </form>
            </DialogContent>
        </Dialog>
            </>
            ) : null}
        </div>
    );
}

function Meetings({ associationId, bodies, terms, policies, meetings, appointments, participants, agendaItems }: Props) {
    const form = useForm({
        body_id: bodies[0]?.id ?? '',
        term_id: terms[0]?.id ?? '',
        meeting_type: 'regular',
        title: '',
        purpose: '',
        starts_at: '',
        ends_at: '',
        timezone: 'Asia/Kolkata',
        mode: 'physical',
        venue: '',
        joining_details: '',
        visibility: 'administrators',
    });
    const [selectedMeetingId, setSelectedMeetingId] = useState(meetings[0]?.id ?? '');
    const [noticeSeparately, setNoticeSeparately] = useState(false);
    const [attendanceOverrides, setAttendanceOverrides] = useState<Record<string, string>>({});
    const [endsEdited, setEndsEdited] = useState(false);
    const participantForm = useForm({ appointment_id: appointments[0]?.id ?? '', guest_name: '', capacity: 'member' });
    const agendaForm = useForm({ meeting_id: selectedMeetingId, subject: '', background: '', motion_text: '' });
    const selectedMeeting = meetings.find((meeting) => meeting.id === selectedMeetingId) ?? meetings[0];
    const selectedParticipants = participants.filter((participant) => participant.meeting_id === selectedMeeting?.id);
    const selectedPolicy = policies
        .filter((policy) => policy.body_id === form.data.body_id && policy.status === 'reviewed' && (!form.data.starts_at || policy.effective_from <= form.data.starts_at.slice(0, 10)))
        .sort((a, b) => `${b.effective_from}-${b.version}`.localeCompare(`${a.effective_from}-${a.version}`))[0];
    const noticeRequired = Number(selectedPolicy?.notice_days ?? 0);
    const noticeDays = form.data.starts_at ? Math.max(0, Math.ceil((new Date(form.data.starts_at).getTime() - Date.now()) / 86400000)) : null;
    const meetingTypeLabels: Record<string, string> = { regular: 'Regular', special: 'Special / Emergency', agm: 'AGM / EGM' };
    const meetingStatus = (state: string) => ({ draft: 'Scheduled', scheduled: 'Scheduled', in_progress: 'In Progress', completed: 'Completed', cancelled: 'Cancelled' }[state] ?? state);
    const attendanceStatus = (participant: Row) => attendanceOverrides[participant.id] ?? participant.attendance_status;
    const votingParticipants = selectedParticipants.filter((participant) => Boolean(participant.voting_eligible));
    const presentVoting = votingParticipants.filter((participant) => attendanceStatus(participant) === 'present').length;
    const quorumRequired = Number(selectedMeeting?.quorum_required ?? 0);
    useEffect(() => {
        if (meetings.length && !meetings.some((meeting) => meeting.id === selectedMeetingId)) setSelectedMeetingId(meetings[0].id);
    }, [meetings, selectedMeetingId]);
    useEffect(() => {
        if (!form.data.starts_at) {
            if (form.data.ends_at) form.setData('ends_at', '');
            setEndsEdited(false);
            return;
        }
        if (endsEdited) return;
        const starts = new Date(form.data.starts_at);
        if (!Number.isNaN(starts.getTime())) {
            starts.setHours(starts.getHours() + 1);
            const local = new Date(starts.getTime() - starts.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            form.setData('ends_at', local);
        }
    }, [form.data.starts_at, endsEdited]);
    const selectMeeting = (meetingId: string) => {
        setSelectedMeetingId(meetingId);
        agendaForm.setData('meeting_id', meetingId);
        setAttendanceOverrides({});
    };
    const updateAttendance = (participant: Row, status: string) => {
        setAttendanceOverrides((current) => ({ ...current, [participant.id]: status }));
        router.patch(`/association-board/${associationId}/participants/${participant.id}`, { attendance_status: status }, { preserveScroll: true });
    };
    return (
        <div className="grid w-full gap-5">
        <section className={card}>
            <h2 className="font-semibold">Schedule a meeting</h2>
            <form
                className="grid gap-3 md:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/association-board/${associationId}/meetings`, {
                        preserveScroll: true,
                    });
                }}
            >
                <label className={label}>
                    Body
                    <SearchableSelect required className={input} value={form.data.body_id} options={bodies.map((body) => ({ value: body.id, label: body.name, keywords: body.body_type }))} onValueChange={(value) => form.setData('body_id', value)} />
                </label>
                <label className={label}>
                    Term
                    <SearchableSelect required className={input} value={form.data.term_id} options={terms.map((term) => ({ value: term.id, label: term.name, keywords: `${term.body_name} ${term.state}` }))} onValueChange={(value) => form.setData('term_id', value)} />
                </label>
                <label className={label}>
                    Title
                    <input
                        className={input}
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </label>
                <label className={label}>
                    Meeting type
                    <select
                        className={input}
                        value={form.data.meeting_type}
                        onChange={(e) => form.setData('meeting_type', e.target.value)}
                    >
                        <option value="regular">Regular</option>
                        <option value="special">Special / Emergency</option>
                        <option value="agm">AGM / EGM</option>
                    </select>
                </label>
                <label className={label}>
                    Starts
                    <input
                        className={input}
                        type="datetime-local"
                        value={form.data.starts_at}
                        onChange={(e) => { setEndsEdited(false); form.setData({ ...form.data, starts_at: e.target.value, ends_at: '' }); }}
                    />
                </label>
                <label className={label}>
                    Ends
                    <input
                        className={input}
                        type="datetime-local"
                        value={form.data.ends_at}
                        onChange={(e) => { setEndsEdited(true); form.setData('ends_at', e.target.value); }}
                    />
                </label>
                <label className={label}>
                    Mode
                    <select
                        className={input}
                        value={form.data.mode}
                        onChange={(e) => form.setData('mode', e.target.value)}
                    >
                        <option value="physical">Physical</option>
                        <option value="online">Online</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                </label>
                <label className={label}>
                    Venue / joining details
                    <input
                        className={input}
                        value={form.data.venue}
                        onChange={(e) => form.setData('venue', e.target.value)}
                    />
                </label>
                <label className={`${label} md:col-span-2`}>
                    Agenda items / summary
                    <textarea className={`${input} min-h-24`} value={form.data.purpose} placeholder="List the discussion points for the meeting notice" onChange={(e) => form.setData('purpose', e.target.value)} />
                </label>
                <div className="flex flex-wrap items-center gap-3 text-sm md:col-span-2">
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={noticeSeparately} onChange={(e) => setNoticeSeparately(e.target.checked)} />
                        I will issue a reviewed notice separately before sending details.
                    </label>
                    {noticeDays !== null ? <span className={`rounded-full px-2 py-1 text-xs font-semibold ${noticeDays >= noticeRequired ? 'bg-emerald-500/15 text-emerald-700' : 'bg-amber-500/15 text-amber-700'}`}>
                        {noticeDays >= noticeRequired ? `Meets ${noticeRequired}-day notice rule` : `Short notice: ${noticeDays} days vs ${noticeRequired} required`}
                    </span> : null}
                </div>
                <Button className="w-fit" type="submit">
                    Schedule meeting
                </Button>
            </form>
        </section>
        <section className={card}>
            <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold">Scheduled meetings &amp; live quorum workspace</h2><p className="text-sm text-muted-foreground">Manage attendance and quorum without leaving the meetings page.</p></div><span className="text-sm text-muted-foreground">{meetings.length} meetings</span></div>
            <div className="overflow-x-auto rounded-lg border"><table className="w-full min-w-[820px] text-left text-sm"><thead className="border-b bg-muted/40"><tr><th className="px-3 py-3">Meeting</th><th className="px-3 py-3">Date &amp; mode</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Quorum</th><th className="px-3 py-3 text-right">Actions</th></tr></thead><tbody>{meetings.map((meeting) => <tr className="border-b last:border-0" key={meeting.id}><td className="px-3 py-3"><strong>{meeting.title}</strong><div className="text-xs text-muted-foreground">{meetingTypeLabels[meeting.meeting_type] ?? meeting.meeting_type} · {meeting.reference}</div></td><td className="px-3 py-3">{new Date(meeting.starts_at).toLocaleString()}<div className="text-xs text-muted-foreground capitalize">{meeting.mode}</div></td><td className="px-3 py-3"><span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold">{meetingStatus(meeting.state)}</span></td><td className="px-3 py-3"><span className={`rounded-full px-2 py-1 text-xs font-semibold ${meeting.quorum_state === 'passed' ? 'bg-emerald-500/15 text-emerald-700' : 'bg-amber-500/15 text-amber-700'}`}>{meeting.quorum_state === 'passed' ? `Quorum Met (${meeting.quorum_present}/${meeting.quorum_required})` : 'Pending'}</span></td><td className="px-3 py-3"><div className="flex flex-wrap justify-end gap-2"><Button type="button" size="sm" variant="outline" onClick={() => selectMeeting(meeting.id)}>Manage attendance</Button><Button asChild type="button" size="sm"><Link href={`/association-board/${associationId}/minutes?meeting=${meeting.id}`}>Record minutes →</Link></Button></div></td></tr>)}</tbody></table></div>
            {selectedMeeting ? <div className="mt-5 grid gap-4 border-t pt-4">
                <div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-semibold">Attendance &amp; live quorum</h3><p className="text-sm text-muted-foreground">{selectedMeeting.title} · {new Date(selectedMeeting.starts_at).toLocaleString()}</p></div><span className={`rounded-full px-3 py-2 text-sm font-semibold ${presentVoting >= quorumRequired && quorumRequired > 0 ? 'bg-emerald-500/15 text-emerald-700' : 'bg-amber-500/15 text-amber-700'}`}>{presentVoting >= quorumRequired && quorumRequired > 0 ? `Quorum Met — ${presentVoting} of ${quorumRequired} required voting members present` : `No Quorum — ${presentVoting} of ${quorumRequired || '—'} required voting members present`}</span></div>
                <div className="overflow-x-auto rounded-lg border"><table className="w-full min-w-[680px] text-left text-sm"><thead className="border-b bg-muted/40"><tr><th className="px-3 py-3">Member</th><th className="px-3 py-3">Position</th><th className="px-3 py-3">Eligibility</th><th className="px-3 py-3">Attendance</th></tr></thead><tbody>{selectedParticipants.map((participant) => <tr className="border-b last:border-0" key={participant.id}><td className="px-3 py-3">{participant.member_name ?? participant.guest_name ?? 'Guest / observer'}</td><td className="px-3 py-3">{participant.position_title ?? 'Observer'}</td><td className="px-3 py-3"><span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold">{participant.voting_eligible ? 'Voting' : 'Non-voting'}</span></td><td className="px-3 py-3"><div className="flex flex-wrap gap-2">{['present', 'excused', 'absent'].map((status) => <button className={`rounded-md border px-2 py-1 text-xs ${attendanceStatus(participant) === status ? 'bg-primary text-primary-foreground' : ''}`} type="button" key={status} onClick={() => updateAttendance(participant, status)}>{status[0].toUpperCase() + status.slice(1)}</button>)}</div></td></tr>)}</tbody></table></div>
                <div className="flex flex-wrap gap-3"><Link className="inline-flex items-center rounded-lg border px-3 py-2 text-sm font-medium" href={`/association-board/${associationId}/minutes?meeting=${selectedMeeting.id}`}>Save attendance &amp; open minutes →</Link></div>
                <div className="grid gap-4 border-t pt-4 md:grid-cols-2">
                    <form className="grid gap-3" onSubmit={(e) => { e.preventDefault(); participantForm.post(`/association-board/${associationId}/meetings/${selectedMeeting.id}/participants`, { preserveScroll: true }); }}><h3 className="font-semibold">Invite participant</h3><SearchableSelect className={input} placeholder="Guest / observer" value={participantForm.data.appointment_id} options={appointments.filter((appointment) => appointment.term_id === selectedMeeting.term_id).map((appointment) => ({ value: appointment.id, label: `${appointment.position_title} · ${appointment.member_name ?? appointment.external_name}`, keywords: appointment.member_name ?? appointment.external_name }))} onValueChange={(value) => participantForm.setData('appointment_id', value)} />{!participantForm.data.appointment_id ? <input className={input} placeholder="Guest name" value={participantForm.data.guest_name} onChange={(e) => participantForm.setData('guest_name', e.target.value)} /> : null}<Button className="w-fit" type="submit">Invite</Button></form>
                    <form className="grid gap-3" onSubmit={(e) => { e.preventDefault(); agendaForm.post(`/association-board/${associationId}/meetings/${selectedMeeting.id}/agenda`, { preserveScroll: true }); }}><h3 className="font-semibold">Add agenda item</h3><input className={input} placeholder="Subject" value={agendaForm.data.subject} onChange={(e) => agendaForm.setData('subject', e.target.value)} /><textarea className={input} placeholder="Motion text (optional)" value={agendaForm.data.motion_text} onChange={(e) => agendaForm.setData('motion_text', e.target.value)} /><Button className="w-fit" type="submit">Add agenda item</Button></form>
                </div>
                <div className="grid gap-2 text-sm">{agendaItems.filter((item) => item.meeting_id === selectedMeeting.id).map((item) => <div className="rounded-lg border p-3" key={item.id}><strong>{item.sequence}. {item.subject}</strong><span className="ml-2 text-muted-foreground">{item.outcome ?? 'pending decision'}</span></div>)}</div>
            </div> : <p className="text-sm text-muted-foreground">Schedule a meeting to open its attendance and quorum workspace.</p>}
        </section>
        </div>
    );
}

function Minutes({ associationId, meetings, minutes, participants, agendaItems, votes, selectedMeetingId }: Props) {
    const [meetingId, setMeetingId] = useState(selectedMeetingId ?? meetings[0]?.id ?? '');
    const meeting = meetings.find((item) => item.id === meetingId) ?? meetings[0];
    const latest = minutes.filter((item) => item.meeting_id === meeting?.id).sort((a, b) => Number(b.version) - Number(a.version))[0];
    const initialContent = typeof latest?.content === 'object' ? latest.content : {};
    const form = useForm({ action: 'save', base_version: latest?.version ?? undefined, content: { summary: initialContent.summary ?? meeting?.purpose ?? '' }, redacted_content: { summary: '' }, comment: '' });
    const resolutionForm = useForm({ subject: '', motion_text: '' });
    const locked = ['approved', 'published'].includes(latest?.state);
    const meetingParticipants = participants.filter((participant) => participant.meeting_id === meeting?.id);
    const attendanceCount = meetingParticipants.filter((participant) => participant.attendance_status === 'present').length;
    const meetingItems = agendaItems.filter((item) => item.meeting_id === meeting?.id);
    const quorumMet = meeting?.quorum_state === 'passed';
    const stateLabel = latest?.state === 'submitted' ? 'Under Review' : latest?.state === 'approved' || latest?.state === 'published' ? 'Approved & Locked' : 'Draft';
    useEffect(() => {
        const content = typeof latest?.content === 'object' ? latest.content : {};
        form.setData({ action: 'save', base_version: latest?.version ?? undefined, content: { summary: content.summary ?? meeting?.purpose ?? '' }, redacted_content: { summary: '' }, comment: '' });
    }, [meeting?.id, latest?.id]);
    const postMinutes = (action: string) => {
        if (!meeting) return;
        form.transform((data) => ({ ...data, action }));
        form.post(`/association-board/${associationId}/meetings/${meeting.id}/minutes`, { preserveScroll: true });
    };
    return (
        <div className="grid w-full gap-5">
            <section className={card}>
                <h2 className="font-semibold">Minutes and resolutions</h2>
                <p className="text-sm text-muted-foreground">Formal minutes remain versioned, reviewable, and immutable after approval.</p>
                {meeting ? <>
                    <SearchableSelect className={input} value={meetingId} options={meetings.map((item) => ({ value: item.id, label: `${item.reference} · ${item.title}`, keywords: `${item.term_name ?? ''} ${item.starts_at}` }))} onValueChange={setMeetingId} placeholder="Select meeting" />
                    <div className="grid gap-3 rounded-lg border bg-muted/20 p-3 text-sm sm:grid-cols-3"><span><strong>Date</strong><br />{new Date(meeting.starts_at).toLocaleString()}</span><span><strong>Attendance</strong><br />{attendanceCount} present of {meetingParticipants.length}</span><span><strong>Quorum</strong><br /><span className={quorumMet ? 'text-emerald-700' : 'text-amber-700'}>{quorumMet ? `Quorum Met (${meeting.quorum_present}/${meeting.quorum_required})` : 'No Quorum'}</span></span></div>
                    {!quorumMet ? <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800">Quorum was not met. Formal binding resolutions cannot be passed until the quorum requirement is satisfied.</p> : null}
                    <div className="flex items-center justify-between gap-3"><h3 className="font-semibold">Structured minutes editor</h3><span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold">{stateLabel}</span></div>
                    <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); postMinutes('save'); }}>
                        <label className={label}>Minutes / discussion notes<textarea className={`${input} min-h-48`} rows={8} disabled={locked} value={form.data.content.summary} onChange={(event) => form.setData('content', { summary: event.target.value })} /></label>
                        {latest?.approved_by && latest?.approved_at ? <p className="text-xs text-muted-foreground">Approved by user {latest.approved_by} at {latest.approved_at}.</p> : null}
                        <div className="flex flex-wrap gap-2">{!locked ? <><Button type="submit">Save draft</Button><Button type="button" variant="outline" onClick={() => postMinutes('submit')}>Submit for review</Button>{latest?.state === 'submitted' ? <Button type="button" onClick={() => postMinutes('approve')}>Approve &amp; lock</Button> : null}</> : <span className="rounded-lg border px-3 py-2 text-sm">Approved &amp; Locked — editing is disabled.</span>}</div>
                    </form>
                </> : <Empty text="Schedule a meeting before drafting minutes." />}
            </section>
            {meeting ? <section className={card}>
                <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-semibold">Formal resolutions &amp; voting</h2><p className="text-sm text-muted-foreground">Record motions, tally votes, and preserve the outcome with the minutes version.</p></div><span className="text-sm text-muted-foreground">{meetingItems.length} resolutions</span></div>
                <form className="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]" onSubmit={(event) => { event.preventDefault(); resolutionForm.post(`/association-board/${associationId}/meetings/${meeting.id}/agenda`, { preserveScroll: true, onSuccess: () => resolutionForm.reset() }); }}><input className={input} disabled={locked} placeholder="Resolution title" value={resolutionForm.data.subject} onChange={(event) => resolutionForm.setData('subject', event.target.value)} /><input className={input} disabled={locked} placeholder="Resolution description" value={resolutionForm.data.motion_text} onChange={(event) => resolutionForm.setData('motion_text', event.target.value)} /><Button type="submit" disabled={locked}>Add resolution</Button></form>
                <div className="grid gap-3">{meetingItems.map((item) => { const outcomeLabel = ({ carried: 'Passed', rejected: 'Rejected', deferred: 'Tabled', tie: 'Tabled' } as Record<string, string>)[item.outcome] ?? 'Pending'; return <article className="grid gap-3 rounded-lg border p-3" key={item.id}><div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-semibold">{item.resolution_number ?? `RES-${String(item.sequence).padStart(2, '0')}: ${item.subject}`}</h3><p className="text-sm text-muted-foreground">{item.motion_text ?? item.background ?? 'No description provided.'}</p></div><span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold">{outcomeLabel}</span></div><div className="flex flex-wrap gap-4 text-sm"><span>For: <strong>{item.for_count ?? 0}</strong></span><span>Against: <strong>{item.against_count ?? 0}</strong></span><span>Abstain: <strong>{item.abstained_count ?? 0}</strong></span></div><div className="flex flex-wrap items-center gap-2">{meetingParticipants.filter((participant) => participant.voting_eligible && participant.attendance_status === 'present').map((participant) => { const vote = votes.find((entry) => entry.agenda_item_id === item.id && entry.participant_id === participant.id)?.choice ?? ''; return <label className="grid gap-1 text-xs" key={participant.id}>{participant.member_name ?? participant.guest_name}<select className={input} disabled={locked || !quorumMet} value={vote} onChange={(event) => router.post(`/association-board/${associationId}/meetings/${meeting.id}/agenda/${item.id}/votes`, { participant_id: participant.id, choice: event.target.value }, { preserveScroll: true })}><option value="">Record vote</option><option value="for">For</option><option value="against">Against</option><option value="abstain">Abstain</option></select></label>; })}</div><div className="flex flex-wrap gap-2">{!item.outcome && !locked ? <Button type="button" size="sm" disabled={!quorumMet} onClick={() => router.post(`/association-board/${associationId}/meetings/${meeting.id}/agenda/${item.id}/finalize`, {}, { preserveScroll: true })}>Finalize outcome</Button> : null}<Button asChild type="button" size="sm" variant="outline"><Link href={`/association-board/${associationId}/actions?meeting=${meeting.id}&agenda_item=${item.id}`}>+ Assign action item</Link></Button></div></article>; })}</div>
            </section> : null}
            <section className={card}><h2 className="font-semibold">Minutes history</h2>{minutes.filter((item) => item.meeting_id === meeting?.id).map((item) => <p className="border-t pt-2 text-sm" key={item.id}>Version {item.version} · {item.state} · {item.created_at}</p>)}</section>
        </div>
    );
}

function Actions({ associationId, actions, appointments, meetings, agendaItems, selectedMeetingId, selectedAgendaItemId }: Props) {
    const form = useForm({
        description: '',
        due_on: '',
        priority: 'normal',
        meeting_id: selectedMeetingId ?? '',
        agenda_item_id: selectedAgendaItemId ?? '',
        assignee_member_id: '',
    });
    const [filter, setFilter] = useState('all');
    const today = new Date().toISOString().slice(0, 10);
    const activeAppointments = appointments.filter((appointment) => ['scheduled', 'serving'].includes(appointment.status) && appointment.member_id).map((appointment) => ({ value: String(appointment.member_id), label: `${appointment.member_name ?? appointment.external_name ?? 'Board member'} · ${appointment.position_title}`, keywords: `${appointment.position_title ?? ''} ${appointment.member_name ?? ''}` }));
    const isOverdue = (action: Row) => Boolean(action.due_on && action.due_on < today && action.status !== 'completed');
    const filteredActions = actions.filter((action) => filter === 'all' || (filter === 'overdue' ? isOverdue(action) : action.status === filter));
    const updateStatus = (action: Row, status: string) => {
        router.patch(`/association-board/${associationId}/actions/${action.id}`, { status }, { preserveScroll: true });
    };
    const uniqueMeetings = meetings.map((meeting) => ({ value: meeting.id, label: `${meeting.reference} · ${meeting.title}`, keywords: meeting.term_name }));
    return (
        <section className={`${card} w-full`}>
            <h2 className="font-semibold">Action tracker</h2>
            <p className="text-sm text-muted-foreground">Turn board decisions into assigned, dated, and trackable work.</p>
            <form
                className="grid gap-3 md:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/association-board/${associationId}/actions`, {
                        preserveScroll: true,
                    });
                }}
            >
                <label className={label}>
                    Source meeting (optional)
                    <SearchableSelect className={input} value={form.data.meeting_id} options={uniqueMeetings} onValueChange={(value) => form.setData({ ...form.data, meeting_id: value, agenda_item_id: '' })} placeholder="Link a meeting" />
                </label>
                <label className={label}>
                    Source resolution (optional)
                    <SearchableSelect className={input} value={form.data.agenda_item_id} options={agendaItems.filter((item) => item.meeting_id === form.data.meeting_id).map((item) => ({ value: item.id, label: item.resolution_number ?? item.subject, keywords: item.motion_text }))} onValueChange={(value) => form.setData('agenda_item_id', value)} placeholder={form.data.meeting_id ? 'Link a resolution' : 'Select a meeting first'} disabled={!form.data.meeting_id} />
                </label>
                <label className="grid gap-1 text-sm font-medium md:col-span-2">
                    Task title &amp; instructions
                    <input
                        className={input}
                        placeholder="Describe the action to be completed"
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                </label>
                <label className={label}>
                    Assigned board member
                    <SearchableSelect className={input} value={form.data.assignee_member_id} options={activeAppointments} onValueChange={(value) => form.setData('assignee_member_id', value)} placeholder="Select active appointee" />
                </label>
                <label className={label}>
                    Due date
                    <input
                        className={input}
                        type="date"
                        value={form.data.due_on}
                        onChange={(e) => form.setData('due_on', e.target.value)}
                    />
                </label>
                <label className={label}>
                    Priority
                    <select
                        className={input}
                        value={form.data.priority}
                        onChange={(e) =>
                            form.setData('priority', e.target.value)
                        }
                    >
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </label>
                <Button className="w-fit self-end" type="submit">
                    Add action
                </Button>
            </form>
            <div className="grid gap-3 border-t pt-4">
                <div className="flex flex-wrap gap-2">
                    {[['all', 'All'], ['open', 'Open'], ['in_progress', 'In Progress'], ['overdue', 'Overdue'], ['completed', 'Completed']].map(([value, title]) => <button className={`rounded-full border px-3 py-1.5 text-sm ${filter === value ? 'bg-primary text-primary-foreground' : value === 'overdue' && actions.some(isOverdue) ? 'border-amber-500/60 text-amber-700' : ''}`} type="button" key={value} onClick={() => setFilter(value)}>{title}{value === 'overdue' && actions.some(isOverdue) ? ' ·' : ''}</button>)}
                </div>
                <div className="overflow-x-auto rounded-lg border"><table className="w-full min-w-[900px] text-left text-sm"><thead className="border-b bg-muted/40"><tr><th className="px-3 py-3">Action</th><th className="px-3 py-3">Source</th><th className="px-3 py-3">Assignee</th><th className="px-3 py-3">Due</th><th className="px-3 py-3">Priority</th><th className="px-3 py-3">Status</th></tr></thead><tbody>{filteredActions.length ? filteredActions.map((action) => <tr className={`border-b last:border-0 ${isOverdue(action) ? 'bg-amber-500/10' : ''}`} key={action.id}><td className="px-3 py-3 font-medium">{action.description}</td><td className="px-3 py-3">{action.meeting_title ? <><div>{action.meeting_reference}</div><div className="text-xs text-muted-foreground">{action.resolution_subject ?? 'Meeting action'}</div></> : <span className="text-muted-foreground">Unlinked</span>}</td><td className="px-3 py-3">{action.assignee_name ?? 'Unassigned'}</td><td className={isOverdue(action) ? 'px-3 py-3 font-semibold text-amber-700' : 'px-3 py-3'}>{action.due_on ?? 'No due date'}</td><td className="px-3 py-3 capitalize">{action.priority === 'normal' ? 'Medium' : action.priority === 'urgent' ? 'High' : action.priority}</td><td className="px-3 py-3"><select className={`${input} min-w-32`} value={action.status} onChange={(event) => updateStatus(action, event.target.value)}><option value="open">Open</option><option value="in_progress">In Progress</option><option value="blocked">Blocked</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></td></tr>) : <tr><td className="px-3 py-4 text-muted-foreground" colSpan={6}>No action items match this filter.</td></tr>}</tbody></table></div>
            </div>
        </section>
    );
}

function Empty({ text }: { text: string }) {
    return <p className="text-sm text-muted-foreground">{text}</p>;
}
