import { useLiveFilters } from '@/hooks/use-live-filters';
import { Head, Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { control } from '@/components/association-membership-fields';
import { paymentLabels, PaymentStatusBadge, planName, type PaymentStatus, type RecordPage } from '@/components/payment-records';
import { RecordsPagination, RecordsTable } from '@/components/records-table';
import { Button } from '@/components/ui/button';

type Payment = {
    id: string; association_name: string; member_name: string; amount: string; currency: string;
    membership_type: string; status: PaymentStatus; due_on: string | null; created_at: string; receipt_id: string | null;
};
type Filters = { q: string; sort: string; direction: 'asc' | 'desc'; status: PaymentStatus | null };

export default function MembershipPayments({ payments, filters, scope, associationFilter }: {
    payments: RecordPage<Payment>; filters: Filters; scope: 'member' | 'admin' | 'audit'; associationFilter: string | null;
}) {
    const title = scope === 'admin' ? 'Application Payments' : scope === 'audit' ? 'Payment audit' : 'My payments';
    const url = scope === 'admin' ? '/association-payments' : scope === 'audit' ? '/filmunity-admin/payments' : '/membership-payments';
    const params = new URLSearchParams({ q: filters.q, sort: filters.sort, direction: filters.direction });
    if (filters.status) params.set('status', filters.status);
    if (associationFilter) params.set('association', associationFilter);

    return <main className="grid min-w-0 gap-4 p-4">
        <Head title={title} /><h1 className="text-xl font-semibold tracking-tight">{title}</h1>
        <PaymentTable key={url} payments={payments} params={params} filters={filters} url={url} />
    </main>;
}

function PaymentTable({ payments, filters, params, url }: { payments: RecordPage<Payment>; filters: Filters; params: URLSearchParams; url: string }) {
    const [expanded, setExpanded] = useState<string | null>(null);
    const columns = [['association', 'Association'], ['member', 'Member'], ['amount', 'Amount'], ['status', 'Status'], ['created_at', 'Requested']] as const;
    const search = useLiveFilters(url, { q: filters.q }, (data) => ({ ...Object.fromEntries(params), q: data.q }));
    function actions(payment: Payment) {
        return <div className="flex flex-wrap gap-2">
            <Button size="sm" asChild variant="outline"><Link href={`/membership-payments/${payment.id}`}>View payment</Link></Button>
            {payment.receipt_id && <Button size="sm" asChild variant="outline"><a href={`/membership-receipts/${payment.receipt_id}`}>Receipt</a></Button>}
        </div>;
    }
    return <section className="grid min-w-0 gap-4" aria-label="Payment records">
        <nav className="flex flex-wrap gap-2" aria-label="Payment status filters">
            {([[null, 'All payments'], ...Object.entries(paymentLabels)] as [PaymentStatus | null, string][]).map(([value, label]) => {
                const next = new URLSearchParams({ ...Object.fromEntries(params), q: search.data.q });
                if (value) next.set('status', value); else next.delete('status');
                return <Button key={label} asChild variant={filters.status === value ? 'default' : 'outline'}>
                    <Link href={`${url}?${next}`}>{label}</Link>
                </Button>;
            })}
        </nav>
        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); search.submit(); }}>
            <label className="grid w-full gap-2 text-sm font-medium sm:max-w-sm">Search payments
                <input className={control} name="q" type="search" maxLength={120} value={search.data.q} onChange={(event) => search.setData('q', event.target.value)} placeholder="Association, member, email or reference" />
            </label>
            <p role="status" className="text-xs text-muted-foreground">{search.processing ? 'Updating results…' : 'Filters update automatically'}</p>
            {search.data.q && <Button variant="ghost" type="button" onClick={() => search.setData('q', '')}>Clear search</Button>}
        </form>
        <RecordsTable>
            <thead className="border-b bg-muted/40"><tr>
                {columns.map(([column, label]) => {
                    const active = filters.sort === column;
                    const next = new URLSearchParams({ ...Object.fromEntries(params), q: search.data.q, sort: column, direction: active && filters.direction === 'asc' ? 'desc' : 'asc' });
                    return <th key={column} scope="col" className="p-3" aria-sort={active ? (filters.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                        <Link className="inline-flex items-center gap-2 hover:underline" href={`${url}?${next}`}>{label}<span aria-hidden="true">{active ? (filters.direction === 'asc' ? '↑' : '↓') : '↕'}</span></Link>
                    </th>;
                })}
                <th className="p-3" scope="col">Actions</th>
            </tr></thead>
            <tbody>
                {payments.data.map((payment) => {
                    const open = expanded === payment.id;
                    return <Fragment key={payment.id}>
                        <tr className="border-b">
                            <th className="p-3 font-medium" scope="row"><button type="button" className="text-left hover:underline"
                                aria-expanded={open} aria-controls={open ? `payment-${payment.id}` : undefined} onClick={() => setExpanded(open ? null : payment.id)}>
                                <span className="mr-2" aria-hidden="true">{open ? '▾' : '▸'}</span>{payment.association_name}
                            </button></th>
                            <td className="p-3">{payment.member_name}</td><td className="p-3 tabular-nums">{payment.currency} {payment.amount}</td>
                            <td className="p-3"><PaymentStatusBadge status={payment.status} /></td><td className="p-3 tabular-nums">{payment.created_at}</td>
                            <td className="p-3">{actions(payment)}</td>
                        </tr>
                        {open && <tr id={`payment-${payment.id}`} className="border-b bg-background/40"><td colSpan={6} className="p-4">
                            <div className="grid gap-3 text-sm">
                                <p>{planName(payment.membership_type)} membership · {payment.id}</p>
                                <p>Due date · {payment.due_on ?? 'No due date'}</p>{actions(payment)}
                            </div>
                        </td></tr>}
                    </Fragment>;
                })}
                {!payments.data.length && <tr><td colSpan={6} className="p-4 text-muted-foreground">No payments match this view.</td></tr>}
            </tbody>
        </RecordsTable>
        <RecordsPagination page={payments} label="Payment pages" singular="payment" plural="payments" />
    </section>;
}

MembershipPayments.layout = { breadcrumbs: [{ title: 'Payments', href: '/membership-payments' }] };
