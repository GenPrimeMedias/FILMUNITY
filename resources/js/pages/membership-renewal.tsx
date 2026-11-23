import { useLiveFilters } from '@/hooks/use-live-filters';
import { Head, Link, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { control, type WorkspaceSettings } from '@/components/association-membership-fields';
import { MembershipTypePill, MembershipStatus, membershipDate, type MembershipState } from '@/components/membership-lifecycle';
import type { RecordPage } from '@/components/payment-records';
import { RecordsPagination, RecordsTable } from '@/components/records-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type Term = { id: string; payment_id: string; starts_at: string; ends_at: string | null; created_at: string;
    amount: string; currency: string; renewal_membership_id: string | null; receipt_id: string | null; receipt_number: string | null };
type Filters = { q: string; sort: string; direction: 'asc' | 'desc' };
const card = 'grid min-w-0 gap-4 rounded-xl border border-t-4 bg-card p-5 text-card-foreground';

export default function MembershipRenewal({ membership, branding, history, filters, isOwner, renewalPlan }: {
    membership: MembershipState & { association_name: string; member_name: string; end_token: string };
    branding: WorkspaceSettings; history: RecordPage<Term>; filters: Filters; isOwner: boolean; renewalPlan?: { name: string; fee: string } | null;
}) {
    const [confirm, setConfirm] = useState(false);
    const form = useForm({ version: branding.version, end_token: membership.end_token, fee: renewalPlan?.fee ?? branding.yearly_fee ?? '', confirmed: false });
    const url = `/membership-renewals/${membership.id}`;
    const accent = { borderTopColor: branding.accent };
    const available = membership.canRenew && renewalPlan !== null && (renewalPlan?.fee ?? branding.yearly_fee) !== null && !membership.renewal_payment;
    return <main className="grid min-w-0 gap-4 p-4">
        <Head title="Membership validity and renewals" />
        <header className="flex flex-wrap items-center justify-between gap-3"><h1 className="text-xl font-semibold">Membership validity and renewals</h1>
            <Button variant="outline" asChild><Link href={isOwner ? '/association-members' : '/membership-renewals'}>{isOwner ? 'Members' : 'My memberships and renewals'}</Link></Button>
        </header>
        <section className={card} style={accent}>
            <div className="flex items-center gap-4">{branding.logo_url && <img src={branding.logo_url} alt="" className="size-16 rounded-lg object-contain" />}<div><h2 className="text-lg font-semibold">{branding.display_name}</h2><p className="text-sm text-muted-foreground">{membership.member_name} · {membership.number}</p></div></div>
            <div className="flex flex-wrap items-center gap-3"><MembershipStatus status={membership.display_status} accent={branding.accent} /><MembershipTypePill type={membership.membership_type} accent={branding.accent} /></div>
            <dl className="grid gap-4 text-sm sm:grid-cols-2"><div><dt className="text-muted-foreground">Member since</dt><dd>{membershipDate(membership.starts_at)}</dd></div><div><dt className="text-muted-foreground">Current expiry</dt><dd>{membershipDate(membership.ends_at)}</dd></div></dl>
        </section>
        <section className={card} style={accent}><h2 className="font-semibold">Yearly renewal</h2>
            {membership.membership_type === 'lifetime' ? <p>Life-Time memberships do not require renewal.</p> : <>
                <p className="text-sm text-muted-foreground">Renewal opens 30 days before expiry. Early renewal adds one year from your current expiry. After expiry, one year starts when the association acknowledges payment. Fees are fixed when you confirm the request.</p>
                {membership.renewal_payment ? <Button className="w-fit" asChild><Link href={`/membership-payments/${membership.renewal_payment.id}`}>{isOwner ? 'Review renewal payment' : 'Continue renewal payment'}</Link></Button>
                    : available ? <><p>{renewalPlan?.name ?? 'Yearly'} renewal fee · INR {renewalPlan?.fee ?? branding.yearly_fee}</p><Button className="w-fit" onClick={() => { form.clearErrors(); setConfirm(true); }}>Review renewal request</Button></>
                    : <p className="text-sm">{isOwner ? 'Members request their own renewal. You can verify renewal payments in Application Payments.' : membership.display_status === 'suspended' ? 'Contact the association about your suspended membership.' : membership.canRenew ? 'The association must configure an active renewal plan and fee.' : 'Renewal is not currently available. Check your expiry date or contact the association.'}</p>}
            </>}
        </section>
        <section className="grid min-w-0 gap-4"><h2 className="font-semibold">Membership term and receipt history</h2>
            <History key={url} history={history} filters={filters} url={url} />
        </section>
        <Dialog open={confirm} onOpenChange={(open) => { if (!form.processing) setConfirm(open); }}>
            <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-xl">
                <DialogClose type="button" aria-label="Close renewal confirmation" className="absolute top-3 right-3 grid size-8 place-items-center rounded border bg-background text-foreground"><span aria-hidden="true">×</span></DialogClose>
                <DialogHeader><DialogTitle>Confirm yearly renewal request</DialogTitle><DialogDescription>Check the association, fee and renewal policy before continuing to payment.</DialogDescription></DialogHeader>
                <form className="grid gap-4" onSubmit={(event) => { event.preventDefault(); form.post(url, { preserveScroll: true, onSuccess: () => setConfirm(false) }); }}>
                    <fieldset className="grid gap-3 rounded-lg border border-t-4 p-4" style={accent}><legend className="px-2 font-semibold">Renewal details</legend>
                        <p>{branding.display_name}</p><p>{membership.number} · {renewalPlan?.name ?? 'Yearly'} · INR {form.data.fee}</p><p className="text-sm">Current expiry: {membershipDate(membership.ends_at)}</p>
                        <p className="text-sm text-muted-foreground">Your membership number stays the same. A new receipt is issued only after payment acknowledgement. A zero-fee renewal still requires association confirmation.</p>
                    </fieldset>
                    <label className="flex items-start gap-3 text-sm"><input type="checkbox" required checked={form.data.confirmed} onChange={(event) => form.setData('confirmed', event.target.checked)} className="mt-1" /><span>I confirm the yearly fee and understand the renewal validity policy.</span></label>
                    {Object.values(form.errors).map((error, i) => <p key={i} role="alert" className="text-sm text-destructive">{error}</p>)}
                    <div className="flex flex-wrap justify-end gap-2"><Button type="button" variant="outline" disabled={form.processing} onClick={() => setConfirm(false)}>Cancel</Button><Button type="submit" disabled={form.processing || !form.data.confirmed}>Confirm and continue to payment</Button></div>
                </form>
            </DialogContent>
        </Dialog>
    </main>;
}
function History({ history, filters, url }: { history: RecordPage<Term>; filters: Filters; url: string }) {
    const [expanded, setExpanded] = useState<string | null>(null);
    const search = useLiveFilters(url, { q: filters.q }, (data) => ({ ...filters, q: data.q }));
    return <>
        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); search.submit(); }}><label className="grid w-full gap-2 text-sm sm:max-w-sm">Search term history<input className={control} type="search" name="q" value={search.data.q} onChange={(event) => search.setData('q', event.target.value)} maxLength={120} placeholder="Receipt number or payment reference" /></label><p role="status" className="text-xs text-muted-foreground">{search.processing ? 'Updating results…' : 'Filters update automatically'}</p>{search.data.q && <Button type="button" variant="ghost" onClick={() => search.setData('q', '')}>Clear search</Button>}</form>
        <RecordsTable><thead className="border-b bg-muted/40"><tr>{[['created_at', 'Issued'], ['starts_at', 'Term starts'], ['ends_at', 'Term ends'], ['amount_minor', 'Fee']].map(([column, label]) => <th className="p-3" key={column} scope="col" aria-sort={filters.sort === column ? filters.direction === 'asc' ? 'ascending' : 'descending' : undefined}><Link href={`${url}?${new URLSearchParams({ ...filters, q: search.data.q, sort: column, direction: filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc' })}`}>{label} {filters.sort === column ? filters.direction === 'asc' ? '↑' : '↓' : '↕'}</Link></th>)}<th scope="col" className="p-3">Receipt</th></tr></thead>
            <tbody>{history.data.map((term) => <Fragment key={term.id}><tr className="border-b"><th scope="row" className="p-3 font-medium"><button type="button" className="text-left" aria-expanded={expanded === term.id} aria-controls={expanded === term.id ? `term-${term.id}` : undefined} onClick={() => setExpanded(expanded === term.id ? null : term.id)}><span aria-hidden="true">{expanded === term.id ? '▾' : '▸'} </span>{membershipDate(term.created_at)}</button></th><td className="p-3">{membershipDate(term.starts_at)}</td><td className="p-3">{membershipDate(term.ends_at)}</td><td className="p-3">{term.currency} {term.amount}</td><td className="p-3">{term.receipt_id ? <a className="underline" href={`/membership-receipts/${term.receipt_id}`}>{term.receipt_number}</a> : 'Not issued'}</td></tr>
                {expanded === term.id && <tr id={`term-${term.id}`} className="border-b bg-background/40"><td colSpan={5} className="p-4"><section className="grid gap-3 rounded-lg border p-4 text-sm"><h3 className="font-semibold">{term.renewal_membership_id ? 'Yearly renewal' : 'Initial membership'}</h3><p className="break-all">Payment reference · {term.payment_id}</p><Button className="w-fit" variant="outline" size="sm" asChild><Link href={`/membership-payments/${term.payment_id}`}>Payment evidence, decision notes and receipt</Link></Button></section></td></tr>}
            </Fragment>)}{!history.data.length && <tr><td colSpan={5} className="p-4 text-muted-foreground">No membership terms match this search.</td></tr>}</tbody>
        </RecordsTable><RecordsPagination page={history} label="Membership term pages" singular="term" plural="terms" />
    </>;
}
MembershipRenewal.layout = { breadcrumbs: [{ title: 'My Memberships', href: '/membership-applications' }, { title: 'Membership renewals', href: '/membership-renewals' }] };
