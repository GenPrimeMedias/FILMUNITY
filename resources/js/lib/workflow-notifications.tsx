import { router } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, X } from 'lucide-react';
import { createRoot } from 'react-dom/client';
import { useEffect, useState } from 'react';

type Notice = { id: number; text: string; kind: 'success' | 'error' | 'warning' };
type Mutation = { visit: object; text: string; completed: boolean };
const listeners = new Set<(notice: Notice) => void>();
let sequence = 0;

export function workflowToast(text: string, kind: Notice['kind'] = 'success') {
    const notice = { id: ++sequence, text, kind };
    listeners.forEach((listener) => listener(notice));
}

function ToastRegion() {
    const [notices, setNotices] = useState<Notice[]>([]);
    useEffect(() => {
        const timers = new Set<ReturnType<typeof setTimeout>>();
        const receive = (notice: Notice) => {
            setNotices((current) => [...current.slice(-2), notice]);
            const timer = setTimeout(() => {
                setNotices((current) => current.filter(({ id }) => id !== notice.id));
                timers.delete(timer);
            }, notice.kind === 'success' ? 6000 : 10000);
            timers.add(timer);
        };
        listeners.add(receive);
        return () => {
            listeners.delete(receive);
            timers.forEach(clearTimeout);
        };
    }, []);

    return <div aria-label="Notifications" className="pointer-events-none fixed top-4 right-4 left-4 z-[100] grid gap-3 sm:left-auto sm:w-96">
        {notices.map((notice) => <div key={notice.id} role={notice.kind === 'error' ? 'alert' : 'status'}
            className="pointer-events-auto flex items-start gap-3 rounded-xl border border-t-4 border-t-[#b9d66b] bg-card p-4 text-card-foreground shadow-lg">
            {notice.kind === 'success' ? <CheckCircle2 aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-primary" />
                : <CircleAlert aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-destructive" />}
            <p className="min-w-0 flex-1 break-words text-sm">{notice.text}</p>
            <button type="button" aria-label="Dismiss notification" className="rounded p-1 hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                onClick={() => setNotices((current) => current.filter(({ id }) => id !== notice.id))}><X aria-hidden="true" className="size-4" /></button>
        </div>)}
    </div>;
}

function firstMessage(value: unknown): string | null {
    if (typeof value === 'string' && value.trim()) return value;
    if (value !== null && typeof value === 'object') {
        for (const item of Object.values(value)) {
            const message = firstMessage(item);
            if (message) return message;
        }
    }
    return null;
}

function resultText(path: string, method: string, data: unknown): string {
    const fields = data !== null && typeof data === 'object' ? data as Record<string, unknown> : {};
    if (path.startsWith('/membership-renewals/')) return 'Renewal request ready. Continue to payment.';
    if (path.endsWith('/renewal-reminder')) return 'Reminder delivery requested. Check the delivery status in the queue.';
    if (path.endsWith('/passport-photo')) return 'Passport photo saved for membership cards.';
    if (path.endsWith('/retry-notices')) return 'Application email delivery updated.';
    if (path.endsWith('/logout')) return 'You have been signed out.';
    if (path.endsWith('/login') || path.endsWith('/two-factor-challenge')) return 'Signed in successfully.';
    if (path.endsWith('/register')) return 'Your account has been created.';
    if (path.endsWith('/email/verification-notification')) return 'Verification email requested. Check your inbox.';
    if (path.includes('/forgot-password')) return 'Password reset requested. Check your inbox.';
    if (path.includes('/reset-password')) return 'Your password has been reset.';
    if (path.endsWith('/professional-profile/draft')) return 'Profile saved.';
    if (path.endsWith('/professional-profile/publication')) return method === 'delete' ? 'Profile unpublished.' : 'Profile published.';
    if (path.endsWith('/association-registration')) return 'Association application submitted.';
    if (/\/association-registration\/[^/]+\/review$/.test(path)) return fields.status === 'declined' ? 'Association application declined.' : 'Association application approved.';
    if (path.endsWith('/approval-email')) return 'Approval email delivery updated.';
    if (/\/association-workspaces\/[^/]+\/setup$/.test(path)) return 'Workspace settings saved.';
    if (path.endsWith('/membership-applications')) return 'Membership application submitted.';
    if (path.endsWith('/confirm-plan')) return 'Membership type and fee confirmed.';
    if (/\/association-membership-review\/[^/]+$/.test(path)) return fields.status === 'declined' ? 'Membership application declined.' : 'Membership application approved.';
    if (path.includes('/membership-payments/request/')) return 'Payment request created.';
    if (/\/membership-payments\/[^/]+\/submissions$/.test(path)) return 'Payment proof submitted for verification.';
    if (/\/association-payments\/[^/]+\/submissions\/[^/]+$/.test(path)) return fields.decision === 'return' ? 'Payment proof returned to the applicant.' : 'Payment acknowledged and membership activated.';
    if (path.endsWith('/zero-fee')) return 'Zero-fee membership activated.';
    if (path.endsWith('/retry-emails')) return 'Notification delivery checked.';
    if (method === 'delete') return 'Deleted successfully.';
    return 'Changes saved successfully.';
}

export function installWorkflowNotifications() {
    const mount = document.createElement('div');
    mount.setAttribute('data-filmunity-notifications', '');
    document.body.append(mount);
    const root = createRoot(mount);
    root.render(<ToastRegion />);
    let mutation: Mutation | null = null;
    const remove = [
        router.on('start', ({ detail: { visit } }) => {
            mutation = visit.method.toLowerCase() === 'get' ? null : {
                visit, text: resultText(visit.url.pathname, visit.method.toLowerCase(), visit.data), completed: false,
            };
        }),
        router.on('success', ({ detail: { page } }) => {
            if (!mutation || mutation.completed) return;
            mutation.completed = true;
            const errors = firstMessage(page.props.errors);
            if (errors) { workflowToast(errors, 'error'); return; }
            const flash = page.props.flash as Record<string, unknown> | undefined;
            const text = firstMessage(flash?.success) ?? firstMessage(flash?.status) ?? mutation.text;
            const pending = page.props.pendingEmails;
            if (typeof pending === 'number' && pending > 0) {
                workflowToast(text + ' Email delivery is pending; you can retry from this page.', 'warning');
            } else workflowToast(text);
        }),
        router.on('error', ({ detail: { errors } }) => {
            if (!mutation || mutation.completed) return;
            mutation.completed = true;
            workflowToast(firstMessage(errors) ?? 'Please review the form and try again.', 'error');
        }),
        router.on('finish', ({ detail: { visit } }) => {
            if (mutation?.visit === visit) mutation = null;
        }),
    ];
    const failed = () => {
        if (!mutation || mutation.completed) return;
        mutation.completed = true;
        workflowToast('The request could not be completed. Please try again.', 'error');
    };
    const failures = ['inertia:invalid', 'inertia:exception', 'inertia:httpException', 'inertia:networkError'];
    failures.forEach((name) => document.addEventListener(name, failed));
    return () => {
        remove.forEach((unsubscribe) => unsubscribe());
        failures.forEach((name) => document.removeEventListener(name, failed));
        root.unmount(); mount.remove();
    };
}

if (typeof document !== 'undefined') {
    let dispose: (() => void) | undefined;
    const start = () => { dispose = installWorkflowNotifications(); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
    if (import.meta.hot) import.meta.hot.dispose(() => {
        document.removeEventListener('DOMContentLoaded', start);
        dispose?.();
    });
}
