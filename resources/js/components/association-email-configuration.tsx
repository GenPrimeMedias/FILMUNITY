import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    ProtectedFields,
    workspaceRequest,
} from '@/components/workspace-edit-protection';

const input = 'rounded-lg border bg-background px-3 py-2';
type Delivery = {
    id: string;
    kind: string;
    recipient: string;
    attempts: number;
    sent_at: string | null;
    last_error: string | null;
    delivery_state: string;
    needs_review: boolean;
};
type Configuration = {
    allowed_ports: number[];
    mode: string;
    sender_email: string;
    sender_name: string;
    reply_to: string;
    host: string;
    port: number;
    encryption: string;
    username: string;
    has_password: boolean;
    provider_reviewed: boolean;
    status: string;
    last_error: string | null;
    tested_at: string | null;
    deliveries: Delivery[];
};
export default function AssociationEmailConfiguration({
    association,
    onDirty,
}: {
    association: string;
    onDirty: (dirty: boolean) => void;
}) {
    const base = `/association-workspaces/${association}/mail`;
    const [saved, setSaved] = useState<Configuration | null>(null);
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const form = useForm({
        mode: 'managed',
        sender_email: '',
        sender_name: '',
        reply_to: '',
        host: '',
        port: 587,
        encryption: 'starttls',
        username: '',
        password: '',
        remove_password: false,
        provider_reviewed: false,
    });
    const review = useForm({ occurrence: '', decision: 'close', reason: '' });
    const load = async () => {
        const data = (await workspaceRequest(base)) as Configuration;
        setSaved(data);
        const values = {
            mode: data.mode,
            sender_email: data.sender_email ?? '',
            sender_name: data.sender_name ?? '',
            reply_to: data.reply_to ?? '',
            host: data.host ?? '',
            port: data.port,
            encryption: data.encryption,
            username: data.username ?? '',
            password: '',
            remove_password: false,
            provider_reviewed: data.provider_reviewed ?? false,
        };
        form.setData(values);
        form.setDefaults(values);
    };
    useEffect(() => {
        void load().catch((error) => setMessage(error.message));
    }, [base]);
    useEffect(() => {
        onDirty(form.isDirty || review.isDirty);
    }, [form.isDirty, review.isDirty, onDirty]);
    return (
        <section className="grid gap-4 rounded-xl border border-t-4 border-t-[#b9d66b] bg-card p-5">
            <h2 className="font-semibold">Email configuration</h2>
            <p className="text-sm">
                FILMUNITY-managed sending uses the authorized platform sender
                unless a deployment administrator has verified another sender.
                Association-owned SMTP requires your provider's authorization,
                domain verification and TLS settings. Possessing an email
                address or receiving a test does not authorize sending from that
                domain. FILMUNITY authentication/security and platform
                registration decisions keep their platform delivery.
            </p>
            <p role="status">
                Saved status: {saved?.status ?? 'Loading'}{' '}
                {saved?.tested_at ? `· ${saved.tested_at}` : ''}.{' '}
                {saved?.last_error}
            </p>
            <ProtectedFields>
                <form
                    className="grid gap-3 md:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(base, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.setData('password', '');
                                void load();
                            },
                        });
                    }}
                >
                    <label className="grid">
                        Sending mode
                        <select
                            className={input}
                            value={form.data.mode}
                            onChange={(event) =>
                                form.setData('mode', event.target.value)
                            }
                        >
                            <option value="managed">
                                FILMUNITY-managed sending
                            </option>
                            <option value="smtp">Association-owned SMTP</option>
                        </select>
                    </label>
                    <label className="grid">
                        Official sender email
                        <input
                            type="email"
                            required
                            maxLength={254}
                            className={input}
                            value={form.data.sender_email}
                            onChange={(event) =>
                                form.setData('sender_email', event.target.value)
                            }
                        />
                    </label>
                    <label className="grid">
                        Sender display name
                        <input
                            required
                            maxLength={160}
                            className={input}
                            value={form.data.sender_name}
                            onChange={(event) =>
                                form.setData('sender_name', event.target.value)
                            }
                        />
                    </label>
                    <label className="grid">
                        Reply-to address
                        <input
                            type="email"
                            maxLength={254}
                            className={input}
                            value={form.data.reply_to}
                            onChange={(event) =>
                                form.setData('reply_to', event.target.value)
                            }
                        />
                    </label>
                    {form.data.mode === 'smtp' && (
                        <>
                            <label className="grid">
                                SMTP host
                                <input
                                    required
                                    maxLength={253}
                                    className={input}
                                    value={form.data.host}
                                    onChange={(event) =>
                                        form.setData('host', event.target.value)
                                    }
                                />
                            </label>
                            <label className="grid">
                                SMTP port
                                <select
                                    className={input}
                                    value={form.data.port}
                                    onChange={(event) =>
                                        form.setData(
                                            'port',
                                            Number(event.target.value),
                                        )
                                    }
                                >
                                    {(
                                        saved?.allowed_ports ?? [465, 587, 2525]
                                    ).map((port) => (
                                        <option key={port}>{port}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid">
                                Encryption
                                <select
                                    className={input}
                                    value={form.data.encryption}
                                    onChange={(event) =>
                                        form.setData(
                                            'encryption',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="starttls">
                                        Required STARTTLS
                                    </option>
                                    <option value="tls">Implicit TLS</option>
                                </select>
                            </label>
                            <label className="grid">
                                SMTP username
                                <input
                                    required
                                    maxLength={255}
                                    className={input}
                                    autoComplete="off"
                                    value={form.data.username}
                                    onChange={(event) =>
                                        form.setData(
                                            'username',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid">
                                SMTP password
                                <input
                                    type="password"
                                    autoComplete="new-password"
                                    className={input}
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData(
                                            'password',
                                            event.target.value,
                                        )
                                    }
                                />
                                <span className="text-xs">
                                    {saved?.has_password
                                        ? 'A password is stored; blank retains it.'
                                        : 'No password stored.'}{' '}
                                    Saved passwords are never returned.
                                </span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={form.data.remove_password}
                                    onChange={(event) =>
                                        form.setData(
                                            'remove_password',
                                            event.target.checked,
                                        )
                                    }
                                />
                                Explicitly remove the saved SMTP password
                            </label>
                        </>
                    )}
                    <label className="flex gap-2 md:col-span-2">
                        <input
                            type="checkbox"
                            required
                            checked={form.data.provider_reviewed}
                            onChange={(event) =>
                                form.setData(
                                    'provider_reviewed',
                                    event.target.checked,
                                )
                            }
                        />
                        I reviewed the provider's sender/domain authorization
                        and encryption requirements. This declaration does not
                        independently verify a domain.
                    </label>
                    <div
                        role="alert"
                        className="text-destructive md:col-span-2"
                    >
                        {Object.values(form.errors).map((error, index) => (
                            <p key={index}>{error}</p>
                        ))}
                    </div>
                    <Button disabled={form.processing} className="w-fit">
                        Save email configuration
                    </Button>
                </form>
            </ProtectedFields>
            <Button
                type="button"
                className="w-fit"
                variant="outline"
                disabled={busy || !saved || saved.status === 'not_configured'}
                onClick={async () => {
                    setBusy(true);
                    try {
                        const result = await workspaceRequest(
                            `${base}/test`,
                            {},
                        );
                        setMessage(result.message);
                        await load();
                    } catch (error) {
                        setMessage((error as Error).message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                Send test email using saved configuration
            </Button>
            <p className="text-sm">
                Tests go only to your verified signed-in account, never an
                arbitrary destination. At most three test requests per minute.
                Internal SMTP networks/ports are blocked unless a deployment
                administrator explicitly configures a self-hosted exception.
            </p>
            <p role="status">{message}</p>
            <h3 className="font-semibold">Existing delivery history</h3>
            <p className="text-sm">
                Latest application, payment and renewal notices use their
                existing delivery logs. Sent records cannot be sent again. An
                uncertain or interrupted sending outcome is blocked until
                reviewed; sending claims older than five minutes become
                reviewable. a review decision itself sends no mail. Billing
                reminder outcomes remain in Invoice reminder preview.
            </p>
            {saved?.deliveries?.map((delivery) => (
                <div
                    className="rounded-lg border p-3 text-sm"
                    key={`${delivery.kind}:${delivery.id}`}
                >
                    <p>
                        {delivery.kind} · {delivery.recipient} ·{' '}
                        {delivery.sent_at ? 'sent' : delivery.delivery_state} ·
                        Attempts {delivery.attempts}
                    </p>
                    <p>{delivery.last_error}</p>
                </div>
            ))}
            {!!saved?.deliveries?.some((delivery) => delivery.needs_review) && (
                <ProtectedFields>
                    <form
                        className="grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const [kind, id] =
                                review.data.occurrence.split(':');
                            review.transform((data) => ({
                                kind,
                                id,
                                decision: data.decision,
                                reason: data.reason,
                            }));
                            review.post(`${base}/review`, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    review.reset();
                                    void load();
                                },
                            });
                        }}
                    >
                        <h3 className="font-semibold">
                            Review uncertain delivery
                        </h3>
                        <label className="grid">
                            Occurrence
                            <select
                                required
                                className={input}
                                value={review.data.occurrence}
                                onChange={(event) =>
                                    review.setData(
                                        'occurrence',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">
                                    Select uncertain occurrence
                                </option>
                                {saved.deliveries
                                    .filter((delivery) => delivery.needs_review)
                                    .map((delivery) => (
                                        <option
                                            key={`${delivery.kind}:${delivery.id}`}
                                            value={`${delivery.kind}:${delivery.id}`}
                                        >
                                            {delivery.kind} ·{' '}
                                            {delivery.recipient} · {delivery.id}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label className="grid">
                            Decision
                            <select
                                className={input}
                                value={review.data.decision}
                                onChange={(event) =>
                                    review.setData(
                                        'decision',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="close">
                                    Close without another send
                                </option>
                                <option value="retry">
                                    Confirmed non-delivery — permit an explicit
                                    retry
                                </option>
                            </select>
                        </label>
                        <label className="grid">
                            Review reason and provider evidence reference
                            <textarea
                                required
                                maxLength={2000}
                                className={input}
                                value={review.data.reason}
                                onChange={(event) =>
                                    review.setData('reason', event.target.value)
                                }
                            />
                        </label>
                        <p className="text-xs">
                            Verify the provider result first. Do not enter
                            passwords, PINs or connection credentials in review
                            notes.
                        </p>
                        <div role="alert">
                            {Object.values(review.errors).map(
                                (error, index) => (
                                    <p key={index}>{error}</p>
                                ),
                            )}
                        </div>
                        <Button disabled={review.processing}>
                            Record delivery review
                        </Button>
                    </form>
                </ProtectedFields>
            )}
        </section>
    );
}
