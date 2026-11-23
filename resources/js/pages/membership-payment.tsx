import { useLiveFilters } from '@/hooks/use-live-filters';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { control } from '@/components/association-membership-fields';
import { PaymentStatusBadge, planName, type PaymentStatus, type RecordPage } from '@/components/payment-records';
import { RecordsPagination, RecordsTable } from '@/components/records-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type Submission = {
    id: string; reference: string; paid_on: string; status: 'submitted' | 'accepted' | 'returned';
    review_note: string | null; reviewed_at: string | null; created_at: string;
};
type Payment = {
    id: string; application_id: string; membership_type: string; amount: string; currency: string;
    zeroFee: boolean; status: PaymentStatus; instructions: string; due_on: string | null;
    created_at: string; acknowledged_at: string | null;
    snapshot: { association_name: string; member_name: string; member_email: string; accent: string; logo: string | null; purpose?: string; plan_name?: string; renewal_policy?: string };
};
const card = 'grid min-w-0 gap-4 rounded-xl border border-t-4 bg-card p-5 text-card-foreground';

export default function MembershipPayment({ payment, canManage, isApplicant, submissions, events, receipt, membership, pendingEmails, isAuditor, proofFilters }: {
    payment: Payment; isAuditor: boolean; canManage: boolean; isApplicant: boolean;
    proofFilters: { proof_q: string; proof_sort: string; proof_direction: 'asc' | 'desc' };
    submissions: RecordPage<Submission>;
    events: RecordPage<{ id: number; action: string; actor: string; note: string | null; created_at: string }>;
    receipt: { id: string; number: string } | null;
    membership: { id: string; number: string; starts_at: string; ends_at: string | null; status: string } | null;
    pendingEmails: number;
}) {
    const currentURL = usePage().url;
    const eventsPage = new URLSearchParams(currentURL.split('?')[1]).get('events_page');
    const proofSearch = useLiveFilters(`/membership-payments/${payment.id}`, { proof_q: proofFilters.proof_q }, (data) => ({ ...proofFilters, ...(eventsPage ? { events_page: eventsPage } : {}), proof_q: data.proof_q }), { resetKeys: ['attempts_page'] });
    const [expanded, setExpanded] = useState<string | null>(null);
    const [decision, setDecision] = useState<{ submission: string | null; action: 'accept' | 'return' | 'zero' } | null>(null);
    const proof = useForm({ reference: '', paid_on: '', proof: null as File | null });
    const verify = useForm({ decision: 'accept', note: '' });
    const retry = useForm({});
    const title = payment.snapshot.association_name;
    const accent = { borderTopColor: payment.snapshot.accent };

    function openDecision(submission: string | null, action: 'accept' | 'return' | 'zero') {
        verify.reset(); verify.clearErrors(); verify.setData('decision', action === 'return' ? 'return' : 'accept');
        setDecision({ submission, action });
    }
    return <main className="grid min-w-0 gap-4 p-4">
        <Head title={payment.snapshot.purpose === 'renewal' ? 'Membership renewal payment' : 'Membership payment'} />
        {payment.snapshot.purpose === 'renewal' && <section className={card} style={accent}><h2 className="font-semibold">Yearly renewal</h2><p className="text-sm">{payment.snapshot.renewal_policy}</p>{membership && (isApplicant || canManage) && <Button asChild variant="outline" className="w-fit"><Link href={`/membership-renewals/${membership.id}`}>Membership validity and term history</Link></Button>}</section>}
        {payment.snapshot.plan_name && <p className="text-sm font-medium">Membership plan · {payment.snapshot.plan_name}</p>}
        <header className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-xl font-semibold tracking-tight">{payment.snapshot.purpose === 'renewal' ? 'Membership renewal payment' : 'Membership payment'}</h1>
            <div className="flex flex-wrap gap-2">
                <Button asChild variant="outline"><Link href={canManage ? '/association-payments' : isAuditor ? '/filmunity-admin/payments' : '/membership-payments'}>Payment list</Link></Button>
                <Button asChild variant="outline"><Link href={canManage ? '/association-membership-review' : isAuditor ? '/filmunity-admin/members' : '/membership-applications'}>{canManage ? 'Application queue' : isAuditor ? 'Registered accounts' : 'My Memberships'}</Link></Button>
            </div>
        </header>
        <section className={card} style={accent}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    {payment.snapshot.logo && <img src={payment.snapshot.logo} alt="" className="size-12 rounded-lg object-contain" />}
                    <div><h2 className="font-semibold">{title}</h2><p className="text-sm text-muted-foreground">{payment.snapshot.member_name} · {payment.snapshot.member_email}</p></div>
                </div>
                <PaymentStatusBadge status={payment.status} />
            </div>
            <p className="text-2xl font-semibold">{payment.currency} {payment.amount}</p>
            <p className="text-sm">{planName(payment.membership_type)} membership · Due date {payment.due_on ?? 'not set'}</p>
            <p className="break-words text-xs text-muted-foreground">Payment reference · {payment.id}</p>
            {payment.instructions && <div className="grid gap-2">
                <h3 className="text-sm font-medium">Payment instructions</h3><p className="whitespace-pre-wrap break-words text-sm">{payment.instructions}</p>
            </div>}
            {payment.zeroFee && payment.status === 'requested' && <div className="grid gap-3">
                <p className="text-sm text-muted-foreground">This is a zero-fee membership. The association can confirm activation.</p>
                {canManage && <Button className="w-fit" onClick={() => openDecision(null, 'zero')}>Confirm zero-fee membership</Button>}
            </div>}
            {membership && <div className="grid gap-2 border-t pt-4 text-sm">
                <p className="font-semibold">Membership {membership.number}</p>
                <p>Starts · {membership.starts_at}</p><p>Ends · {membership.ends_at ?? 'Life-Time'}</p>
                {isApplicant && <Button className="w-fit" asChild><Link href="/my-association-workspaces">My association workspaces</Link></Button>}
            </div>}
            {receipt && <div className="flex flex-wrap gap-2">
                <Button asChild><a href={`/membership-receipts/${receipt.id}`}>View receipt · {receipt.number}</a></Button>
                <Button asChild variant="outline"><a href={`/membership-receipts/${receipt.id}?download=1`}>Download receipt</a></Button>
            </div>}
            {pendingEmails > 0 && (isApplicant || canManage) && <div className="grid gap-2">
                <p role="status" className="text-sm text-muted-foreground">{pendingEmails} notification emails are waiting for delivery.</p>
                <Button variant="outline" className="w-fit" disabled={retry.processing} onClick={() => retry.post(`/membership-payments/${payment.id}/retry-emails`, { preserveScroll: true })}>Retry email delivery</Button>
            </div>}
        </section>
        {isApplicant && payment.status === 'requested' && !payment.zeroFee && <form className={card} style={accent} onSubmit={(event) => {
            event.preventDefault(); proof.post(`/membership-payments/${payment.id}/submissions`, { forceFormData: true, onSuccess: () => proof.reset() });
        }}>
            <h2 className="font-semibold">Submit payment proof</h2>
            <p className="text-sm text-muted-foreground">Pay the full requested amount using the instructions above, then submit your transaction details.</p>
            <div className="grid gap-4 md:grid-cols-2">
                <label className="grid gap-2 text-sm font-medium">Transaction reference
                    <input className={control} required maxLength={120} value={proof.data.reference} onChange={(event) => proof.setData('reference', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium">Payment date
                    <input type="date" className={control} required value={proof.data.paid_on} onChange={(event) => proof.setData('paid_on', event.target.value)} />
                </label>
            </div>
            <label className="grid gap-2 text-sm font-medium">Payment proof
                <input type="file" required accept=".pdf,.jpg,.jpeg,.png" className={control} onChange={(event) => proof.setData('proof', event.target.files?.[0] ?? null)} />
                <span className="text-xs font-normal text-muted-foreground">PDF, JPEG or PNG up to 5 MB.</span>
            </label>
            {Object.values(proof.errors).map((error, index) => <p role="alert" className="text-sm text-destructive" key={index}>{error}</p>)}
            <Button className="w-fit" type="submit" disabled={proof.processing}>Submit for verification</Button>
        </form>}
        <section className="grid min-w-0 gap-3">
            <h2 className="font-semibold">Payment submissions</h2>
            <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
                event.preventDefault();
                proofSearch.submit();
            }}>
                <label className="grid w-full gap-2 text-sm font-medium sm:max-w-sm">Search submissions
                    <input className={control} name="proof_q" type="search" maxLength={120} value={proofSearch.data.proof_q} onChange={(event) => proofSearch.setData('proof_q', event.target.value)} placeholder="Transaction reference" />
                </label>
                <p role="status" className="text-xs text-muted-foreground">{proofSearch.processing ? 'Updating results…' : 'Filters update automatically'}</p>
                {proofSearch.data.proof_q && <Button type="button" variant="ghost" onClick={() => proofSearch.setData('proof_q', '')}>Clear search</Button>}
            </form>
            <RecordsTable>
                <thead className="border-b bg-muted/40"><tr>
                    {([['reference', 'Transaction reference'], ['paid_on', 'Paid on'], ['status', 'Status']] as const).map(([column, label]) => {
                        const active = proofFilters.proof_sort === column;
                        const direction = active && proofFilters.proof_direction === 'asc' ? 'desc' : 'asc';
                        const params = new URLSearchParams({ ...proofFilters, proof_q: proofSearch.data.proof_q, proof_sort: column, proof_direction: direction });
                        return <th key={column} className="p-3" scope="col" aria-sort={active ? (proofFilters.proof_direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                            <Link preserveScroll className="inline-flex items-center gap-2 hover:underline" href={`/membership-payments/${payment.id}?${params}`}>{label}<span aria-hidden="true">{active ? (proofFilters.proof_direction === 'asc' ? '↑' : '↓') : '↕'}</span></Link>
                        </th>;
                    })}
                    <th className="p-3" scope="col">Actions</th>
                </tr></thead>
                <tbody>{submissions.data.map((item) => {
                    const open = expanded === item.id;
                    return <Fragment key={item.id}>
                        <tr className="border-b">
                            <th scope="row" className="p-3 font-medium"><button type="button" className="text-left hover:underline" aria-expanded={open} aria-controls={open ? `submission-${item.id}` : undefined}
                                onClick={() => setExpanded(open ? null : item.id)}><span aria-hidden="true" className="mr-2">{open ? '▾' : '▸'}</span>{item.reference}</button></th>
                            <td className="p-3">{item.paid_on}</td><td className="p-3 capitalize">{item.status}</td>
                            <td className="p-3"><div className="flex flex-wrap gap-2">
                                <Button size="sm" variant="outline" asChild><a href={`/membership-payments/${payment.id}/submissions/${item.id}/proof`}>Download proof</a></Button>
                                {canManage && item.status === 'submitted' && <>
                                    <Button size="sm" onClick={() => openDecision(item.id, 'accept')}>Acknowledge payment</Button>
                                    <Button size="sm" variant="destructive" onClick={() => openDecision(item.id, 'return')}>Return proof</Button>
                                </>}
                            </div></td>
                        </tr>
                        {open && <tr id={`submission-${item.id}`} className="border-b bg-background/40"><td colSpan={4} className="p-4">
                            <div className="grid gap-2 text-sm"><p>Submitted · {item.created_at}</p>
                                {item.reviewed_at && <p>Reviewed · {item.reviewed_at}</p>}
                                {item.review_note && <p className="whitespace-pre-wrap break-words">Decision note · {item.review_note}</p>}
                            </div>
                        </td></tr>}
                    </Fragment>;
                })}
                {!submissions.data.length && <tr><td colSpan={4} className="p-4 text-muted-foreground">No payment proof submitted.</td></tr>}
                </tbody>
            </RecordsTable>
            <RecordsPagination page={submissions} label="Payment submission pages" singular="submission" plural="submissions" />
        </section>
        <section className="grid gap-3">
            <h2 className="font-semibold">Activity</h2>
            <div className="grid gap-3 rounded-xl border bg-card p-4">
                {events.data.map((event) => <div key={event.id} className="grid gap-1 text-sm">
                    <p className="font-medium">{event.action.replaceAll('_', ' ')}</p>
                    <p className="text-muted-foreground">{event.actor} · {event.created_at}</p>
                    {event.note && <p className="whitespace-pre-wrap break-words">{event.note}</p>}
                </div>)}
            </div>
            <RecordsPagination page={events} label="Payment activity pages" singular="event" plural="events" />
        </section>
        <Dialog open={decision !== null} onOpenChange={(open) => { if (!open && !verify.processing) setDecision(null); }}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{decision?.action === 'return' ? 'Return payment proof?' : decision?.action === 'zero' ? 'Activate zero-fee membership?' : 'Acknowledge payment and activate membership?'}</DialogTitle>
                    <DialogDescription>{decision?.action === 'return'
                        ? 'The applicant will see your reason and can submit corrected evidence.'
                        : decision?.action === 'zero' ? 'Confirm this zero-fee membership. A confirmation receipt and membership will be issued.'
                        : 'Confirm the transaction details. A receipt and association membership will be issued.'}</DialogDescription>
                </DialogHeader>
                <form className="grid gap-3" onSubmit={(event) => {
                    event.preventDefault(); if (!decision) return;
                    const options = { preserveScroll: true, onSuccess: () => setDecision(null) };
                    if (decision.action === 'zero') verify.post(`/association-payments/${payment.id}/zero-fee`, options);
                    else verify.patch(`/association-payments/${payment.id}/submissions/${decision.submission}`, options);
                }}>
                    <label className="grid gap-2 text-sm font-medium">{decision?.action === 'return' ? 'Reason for returning' : 'Note (optional)'}
                        <textarea className={control} rows={3} maxLength={2000} required={decision?.action === 'return'} value={verify.data.note} onChange={(event) => verify.setData('note', event.target.value)} />
                    </label>
                    {Object.values(verify.errors).map((error, index) => <p role="alert" key={index} className="text-sm text-destructive">{error}</p>)}
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="outline" disabled={verify.processing} onClick={() => setDecision(null)}>Cancel</Button>
                        <Button type="submit" variant={decision?.action === 'return' ? 'destructive' : 'default'} disabled={verify.processing}>Confirm</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </main>;
}

MembershipPayment.layout = { breadcrumbs: [{ title: 'Payments', href: '/membership-payments' }] };
