import { SearchableSelect } from '@/components/searchable-select';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { control } from '@/components/association-membership-fields';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export type PaymentApplication = {
    eligible_individual?: boolean; plan_name?: string | null;
    id: string; membership_type: 'yearly' | 'lifetime' | null; fee: string | null; status: string;
    payment?: { id: string; status: string } | null;
    payment_instructions?: string | null;
    plan_options?: { plans?: { key: string; name: string; term: string; fee: string }[]; version: number; yearly: string | null; lifetime: string | null } | null;
};

export function MembershipPaymentActions({ application, reviewing }: { application: PaymentApplication; reviewing: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ instructions: application.payment_instructions ?? '', due_on: '' });
    const plan = useForm({ membership_type: '', plan_id: '', version: application.plan_options?.version ?? 0 });

    if (application.payment) {
        return <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline"><Link href={`/membership-payments/${application.payment.id}`}>Payment and receipt</Link></Button>
            {!reviewing && application.payment.status === 'acknowledged' &&
                <Button asChild><Link href="/my-association-workspaces">My association workspaces</Link></Button>}
        </div>;
    }
    if (application.status !== 'accepted_for_payment') return null;
    if (application.eligible_individual === false) return <p className="text-sm text-muted-foreground">This applicant represents an association. New membership plan confirmation and payment requests are unavailable.</p>;
    if (!application.membership_type || application.fee === null) {
        if (reviewing) return <p className="text-sm text-muted-foreground">Waiting for the applicant to confirm a membership type and fee in My Memberships.</p>;
        const options = application.plan_options;
        if (!options || options.yearly === null || options.lifetime === null) {
            return <p className="text-sm text-muted-foreground">The association must configure both membership fees before you can confirm your plan.</p>;
        }
        return <form className="grid gap-3 rounded-lg border p-4" onSubmit={(event) => {
            event.preventDefault();
            plan.post(`/membership-applications/${application.id}/confirm-plan`, { preserveScroll: true });
        }}>
            <label className="grid gap-2 text-sm font-medium">Confirm membership type and current fee
                <SearchableSelect className={control} required aria-label="Membership plan" placeholder="Select a membership" value={plan.data.plan_id || plan.data.membership_type}
                    options={(options.plans ?? [{ key: 'yearly', name: 'Yearly', term: 'yearly', fee: options.yearly }, { key: 'lifetime', name: 'Life-Time', term: 'lifetime', fee: options.lifetime }]).map((item) => ({ value: item.key, label: `${item.name} · ${item.term === 'yearly' ? 'Yearly' : 'Life-Time'} · INR ${item.fee}`, keywords: item.key }))}
                    onValueChange={(key) => plan.setData({ ...plan.data, membership_type: ['yearly', 'lifetime'].includes(key) ? key : '', plan_id: ['yearly', 'lifetime'].includes(key) ? '' : key })} />
            </label>
            {Object.values(plan.errors).map((error, index) => <p key={index} role="alert" className="text-sm text-destructive">{error}</p>)}
            <Button className="w-fit" type="submit" disabled={plan.processing}>Confirm plan and fee</Button>
        </form>;
    }
    if (!reviewing) return <p className="text-sm text-muted-foreground">Your application is approved. The association will send your payment request.</p>;

    return <>
        <Button className="w-fit" onClick={() => setOpen(true)}>Request payment</Button>
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent>
                <DialogHeader><DialogTitle>Send membership payment request</DialogTitle>
                    <DialogDescription>{application.plan_name ?? (application.membership_type === 'yearly' ? 'Yearly' : 'Life-Time')} · INR {application.fee}. The amount is recorded on this application.</DialogDescription></DialogHeader>
                <form className="grid gap-3" onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/membership-payments/request/${application.id}`, { onSuccess: () => setOpen(false) });
                }}>
                    <label className="grid gap-2 text-sm font-medium">Payment instructions
                        <textarea className={control} rows={4} required={application.fee !== '0.00'} maxLength={3000}
                            value={form.data.instructions} onChange={(event) => form.setData('instructions', event.target.value)} />
                    </label>
                    <label className="grid gap-2 text-sm font-medium">Due date (optional)
                        <input type="date" className={control} value={form.data.due_on} onChange={(event) => form.setData('due_on', event.target.value)} />
                    </label>
                    {Object.values(form.errors).map((error, index) => <p key={index} role="alert" className="text-sm text-destructive">{error}</p>)}
                    <Button type="submit" disabled={form.processing}>Send payment request</Button>
                </form>
            </DialogContent>
        </Dialog>
    </>;
}
