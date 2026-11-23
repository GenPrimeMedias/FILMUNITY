import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

export type MembershipState = {
    id: string; number: string; membership_type: string; display_status: string;
    starts_at: string; ends_at: string | null; canRenew: boolean;
    renewal_payment?: { id: string; status: string } | null;
};
export const membershipStatuses: Record<string, string> = {
    active: 'Active', expiring_soon: 'Expiring soon', expired: 'Expired', lifetime: 'Life-Time',
    suspended: 'Suspended', not_started: 'Not started', invalid_term: 'Contact association',
};
export function MembershipStatus({ status, accent = '#b9d66b' }: { status: string; accent?: string }) {
    return <span className="inline-flex rounded-full border bg-muted px-3 py-1 text-xs font-medium" style={status === 'active' ? { backgroundColor: accent, borderColor: accent, color: '#172115' } : undefined}>{membershipStatuses[status] ?? status}</span>;
}
export function MembershipTypePill({ type, accent = '#b9d66b' }: { type: string; accent?: string }) {
    return <span className="inline-flex rounded-full border px-3 py-1 text-xs font-medium" style={{ backgroundColor: accent, borderColor: accent, color: '#172115' }}>{type === 'yearly' ? 'Yearly membership' : 'Life-Time membership'}</span>;
}
export function MembershipLifecycleActions({ membership, reviewing = false }: { membership: MembershipState; reviewing?: boolean }) {
    return <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" variant="outline" asChild><a href={`/membership-cards/association/${membership.id}`}>Digital membership card</a></Button>
        <Button size="sm" variant="outline" asChild><Link href={`/membership-renewals/${membership.id}`}>Validity and renewal history</Link></Button>
        {membership.renewal_payment ? <Button size="sm" asChild><Link href={`/membership-payments/${membership.renewal_payment.id}`}>{reviewing ? 'Review renewal payment' : 'Continue renewal payment'}</Link></Button>
            : !reviewing && membership.canRenew && <Button size="sm" asChild><Link href={`/membership-renewals/${membership.id}`}>Renew yearly membership</Link></Button>}
    </div>;
}
export function membershipDate(value: string | null): string {
    if (!value) return 'Life-Time';
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
