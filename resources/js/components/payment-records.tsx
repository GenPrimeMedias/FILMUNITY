export const paymentLabels = {
    requested: 'Awaiting payment',
    submitted: 'Pending verification',
    acknowledged: 'Acknowledged',
};
export type PaymentStatus = keyof typeof paymentLabels;
export type RecordPage<T> = {
    data: T[]; total: number; current_page: number; last_page: number;
    prev_page_url: string | null; next_page_url: string | null;
};
export const planName = (type: string) => type === 'yearly' ? 'Yearly' : 'Life-Time';
export function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
    return <span className="rounded-full bg-muted px-3 py-1 text-sm">{paymentLabels[status]}</span>;
}
