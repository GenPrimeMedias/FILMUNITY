import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export type ReminderState = { status: string; sent_at: string | null; attempts: number; last_error: string | null; reminder_on: string };
export function MembershipReminderAction({ id, name, association, reminder }: { id: string; name: string; association: string; reminder?: ReminderState | null }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ confirmed: true });
    const today = new Date().toISOString().slice(0, 10);
    const sameDay = reminder?.reminder_on === today;
    const delivered = sameDay && reminder?.status === 'sent';
    const exhausted = sameDay && (reminder?.attempts ?? 0) >= 5;
    return <div className="grid gap-2">
        <Button size="sm" disabled={delivered || exhausted || form.processing} onClick={() => setOpen(true)}>{delivered ? 'Reminder sent today' : sameDay && reminder?.status === 'failed' ? 'Retry reminder email' : 'Send reminder email'}</Button>
        {reminder && <p className="text-xs text-muted-foreground">{reminder.status === 'sent' ? `Last reminder sent · ${reminder.sent_at}` : sameDay ? reminder.last_error ?? `Delivery ${reminder.status}` : 'A previous reminder is recorded.'}</p>}
        <Dialog open={open} onOpenChange={(value) => { if (!form.processing) setOpen(value); }}><DialogContent>
            <DialogClose type="button" aria-label="Close reminder confirmation" className="absolute top-3 right-3 grid size-8 place-items-center rounded border bg-background"><span aria-hidden="true">×</span></DialogClose>
            <DialogHeader><DialogTitle>Send renewal reminder?</DialogTitle><DialogDescription>The member will receive an email identifying this association and their expiry date.</DialogDescription></DialogHeader>
            <section className="grid gap-2 rounded-lg border border-t-4 border-t-primary p-4 text-sm"><p className="font-semibold">{association}</p><p>Recipient: {name}</p><p>This does not create a payment request or extend membership validity.</p></section>
            {Object.values(form.errors).map((error, i) => <p key={i} role="alert" className="text-sm text-destructive">{error}</p>)}
            <div className="flex justify-end gap-2"><Button variant="outline" disabled={form.processing} onClick={() => setOpen(false)}>Cancel</Button><Button disabled={form.processing} onClick={() => form.post(`/association-members/${id}/renewal-reminder`, { preserveScroll: true, onSuccess: () => setOpen(false) })}>Confirm send</Button></div>
        </DialogContent></Dialog>
    </div>;
}
