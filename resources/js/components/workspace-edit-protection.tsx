import { router } from '@inertiajs/react';
import {
    createContext,
    useContext,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { Button } from '@/components/ui/button';

const EditContext = createContext({
    editable: false,
    association: '',
    resource: '',
});
export function ProtectedFields({ children }: { children: ReactNode }) {
    const context = useContext(EditContext);
    return (
        <fieldset
            disabled={!context.editable}
            data-protected-association={context.association}
            data-protected-resource={context.resource}
            className="contents"
        >
            {children}
        </fieldset>
    );
}

type Status = {
    version: number;
    locked_until?: string | null;
    events?: {
        action: string;
        resource: string;
        actor_id: number;
        created_at: string;
    }[];
    configured: boolean;
    unlocked: boolean;
    editor: string | null;
    google: boolean;
};
type Lease = { token: string; version: number };
const input = 'rounded-lg border bg-background px-3 py-2';

export async function workspaceRequest(
    url: string,
    data?: Record<string, unknown>,
) {
    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));
    const response = await fetch(url, {
        method: data ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': cookie
                ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
                : '',
        },
        body: data ? JSON.stringify(data) : undefined,
    });
    const result = await response.json();
    if (!response.ok)
        throw new Error(
            Object.values(result.errors ?? {})
                .flat()
                .join(' ') ||
                result.message ||
                'Request failed. Reload before editing.',
        );
    return result;
}

export function WorkspaceEditProtection({
    association,
    resource,
    dirty,
    onDiscard,
    onSaved,
    children,
}: {
    association: string;
    resource: 'workspace' | 'masters' | 'finance-policy' | 'tax-configuration';
    dirty: boolean;
    onDiscard?: () => void;
    onSaved?: (path: string) => void;
    children: ReactNode;
}) {
    const base = `/association-workspaces/${association}/protection`;
    const [status, setStatus] = useState<Status | null>(null);
    const [lease, setLease] = useState<Lease | null>(null);
    const [message, setMessage] = useState('Loading workspace protection…');
    const [password, setPassword] = useState('');
    const [pin, setPin] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [reset, setReset] = useState(false);
    const [busy, setBusy] = useState(false);
    const liveLease = useRef<Lease | null>(null);
    const unsaved = useRef(dirty);
    const clean = useRef(false);
    const pendingMutation = useRef<string | null>(null);
    const container = useRef<HTMLDivElement>(null);
    const callbacks = useRef({ onDiscard, onSaved });
    callbacks.current = { onDiscard, onSaved };
    unsaved.current = dirty && !clean.current;

    const load = (refreshVersion = false) =>
        workspaceRequest(`${base}?resource=${resource}`).then((value) => {
            setStatus((previous) => ({
                ...value,
                version: refreshVersion
                    ? value.version
                    : (previous?.version ?? value.version),
            }));
            setMessage(
                value.editor
                    ? `Read-only: ${value.editor} holds an edit lease.`
                    : 'Read-only until PIN unlock and Start protected edit.',
            );
        });
    useEffect(() => {
        setStatus(null);
        setLease(null);
        void load().catch((error) => setMessage(error.message));
    }, [base, resource]);

    const release = (keepalive = false) => {
        const active = liveLease.current;
        liveLease.current = null;
        if (!active) return;
        if (keepalive) {
            const cookie = document.cookie
                .split('; ')
                .find((value) => value.startsWith('XSRF-TOKEN='));
            void fetch(`${base}/lease`, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': cookie
                        ? decodeURIComponent(cookie.slice(11))
                        : '',
                },
                body: JSON.stringify({
                    resource,
                    operation: 'release',
                    token: active.token,
                }),
            }).catch(() => {});
        } else {
            void workspaceRequest(`${base}/lease`, {
                resource,
                operation: 'release',
                token: active.token,
            }).catch(() => {});
        }
    };

    useEffect(() => {
        const matches = (pathname: string) =>
            resource === 'workspace'
                ? [
                      `/association-workspaces/${association}/setup`,
                      `/association-workspaces/${association}/mail`,
                      `/association-workspaces/${association}/mail/review`,
                  ].includes(pathname)
                : resource === 'masters'
                  ? pathname.startsWith(
                        `/association-finance/${association}/masters/`,
                    ) ||
                    [
                        `/association-finance/${association}/account`,
                        `/association-finance/${association}/tally/mappings`,
                    ].includes(pathname)
                  : resource === 'finance-policy'
                    ? pathname ===
                      `/association-finance/${association}/settings`
                    : [
                          `/association-finance/${association}/tax/settings`,
                          `/association-finance/${association}/tax/vendor-reviews`,
                      ].includes(pathname);
        const before = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get' && matches(visit.url.pathname)) {
                if (!liveLease.current) {
                    setMessage(
                        'PIN unlock and a current edit lease are required before Save.',
                    );
                    return false;
                }
                visit.headers['X-Workspace-Lease'] = liveLease.current.token;
                visit.headers['X-Workspace-Version'] = String(
                    liveLease.current.version,
                );
                pendingMutation.current = visit.url.pathname;
            } else if (visit.method === 'get') {
                if (
                    unsaved.current &&
                    !window.confirm(
                        'Discard unsaved changes? Only the last saved configuration will remain.',
                    )
                )
                    return false;
                if (unsaved.current) callbacks.current.onDiscard?.();
                clean.current = true;
                unsaved.current = false;
                release(true);
                setLease(null);
            }
        });
        const success = router.on('success', () => {
            // A protected successful save consumes the lease on the server.
            if (liveLease.current && pendingMutation.current) {
                const savedPath = pendingMutation.current;
                pendingMutation.current = null;
                clean.current = false;
                release(true);
                setLease(null);
                callbacks.current.onSaved?.(savedPath);
                void load(true).catch((error) => setMessage(error.message));
            }
        });
        const error = router.on('error', () => {
            pendingMutation.current = null;
        });
        const closeProtectedDialog = (event: Event) => {
            const dialog = document.querySelector(
                `[data-slot="dialog-content"]:has([data-protected-association="${association}"][data-protected-resource="${resource}"])`,
            );
            const target = event.target as HTMLElement;
            const discardControl = target.closest('[data-workspace-discard]');
            const inlineDiscard =
                discardControl && container.current?.contains(discardControl);
            if (!dialog && !inlineDiscard) return;
            const closeButton =
                event.type === 'click' &&
                target.closest(
                    '[data-slot="dialog-close"], [data-workspace-discard]',
                );
            const escape =
                event.type === 'keydown' &&
                (event as KeyboardEvent).key === 'Escape';
            const outside =
                dialog &&
                event.type === 'pointerdown' &&
                target.closest('[data-slot="dialog-overlay"]');
            if (!closeButton && !escape && !outside) return;
            if (
                unsaved.current &&
                !window.confirm(
                    'Discard unsaved changes and close this editor? Only saved settings remain.',
                )
            ) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            callbacks.current.onDiscard?.();
            clean.current = true;
            unsaved.current = false;
            release(true);
            setLease(null);
        };
        const unload = (event: BeforeUnloadEvent) => {
            if (unsaved.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', unload);
        document.addEventListener('click', closeProtectedDialog, true);
        document.addEventListener('keydown', closeProtectedDialog, true);
        document.addEventListener('pointerdown', closeProtectedDialog, true);
        return () => {
            before();
            success();
            error();
            window.removeEventListener('beforeunload', unload);
            document.removeEventListener('click', closeProtectedDialog, true);
            document.removeEventListener('keydown', closeProtectedDialog, true);
            document.removeEventListener(
                'pointerdown',
                closeProtectedDialog,
                true,
            );
            release(true);
        };
    }, [association, resource]);

    useEffect(() => {
        if (!lease) return;
        const timer = window.setInterval(() => {
            void workspaceRequest(`${base}/lease`, {
                resource,
                operation: 'heartbeat',
                token: lease.token,
            }).catch((error) => {
                liveLease.current = null;
                setLease(null);
                setMessage(
                    `Read-only: connection/unlock/lease lost. ${error.message} Your unsaved entries remain on this page; reload/review before saving.`,
                );
            });
        }, 25000);
        return () => window.clearInterval(timer);
    }, [base, resource, lease]);

    const action = async (
        operation: string,
        values: Record<string, unknown>,
    ) => {
        setBusy(true);
        try {
            const result = await workspaceRequest(
                `${base}/${operation}`,
                values,
            );
            if (operation === 'lease') {
                liveLease.current = result;
                setLease(result);
                setMessage(
                    'Exclusive protected edit active. Save commits atomically; Cancel releases it.',
                );
            } else {
                setPin('');
                setConfirmation('');
                setPassword('');
                if (operation === 'pin') setReset(false);
                await load();
            }
        } catch (error) {
            setMessage((error as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const cancel = () => {
        if (
            unsaved.current &&
            !window.confirm(
                'Discard unsaved changes and release this edit? Saved settings remain in use.',
            )
        )
            return;
        callbacks.current.onDiscard?.();
        clean.current = true;
        unsaved.current = false;
        release();
        setLease(null);
        void load();
    };

    return (
        <EditContext.Provider
            value={{ editable: !!lease, association, resource }}
        >
            <div
                ref={container}
                className="contents"
                onChangeCapture={() => {
                    clean.current = false;
                }}
            >
                <section
                    className="grid gap-3 rounded-xl border border-t-4 border-t-[#b9d66b] bg-card p-4"
                    aria-label="Workspace edit protection"
                >
                    <h2 className="font-semibold">Workspace edit protection</h2>
                    <p role="status" className="text-sm">
                        {message}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        The six-digit PIN adds editing protection; it never
                        replaces login or association permissions. Unlock lasts
                        10 minutes; leases expire after 90 seconds without a
                        heartbeat. Members continue using saved settings.
                    </p>
                    {status && (!status.configured || reset) && (
                        <div className="grid gap-3">
                            <p>
                                Confirm your account, then create a new
                                six-digit PIN. Existing PINs cannot be
                                recovered. Reset invalidates all existing
                                unlocks and edit leases.
                            </p>
                            <label className="grid">
                                Account password
                                <input
                                    className={input}
                                    type="password"
                                    autoComplete="current-password"
                                    value={password}
                                    onChange={(event) =>
                                        setPassword(event.target.value)
                                    }
                                />
                            </label>
                            <Button
                                type="button"
                                disabled={busy || !password}
                                onClick={() =>
                                    void action('confirm', { password })
                                }
                            >
                                Confirm account password
                            </Button>
                            {status.google && (
                                <a
                                    className="underline"
                                    href="/workspace-security/google/redirect"
                                >
                                    Reauthenticate linked Google account
                                </a>
                            )}
                            <label className="grid">
                                New six-digit PIN
                                <input
                                    className={input}
                                    type="password"
                                    inputMode="numeric"
                                    autoComplete="new-password"
                                    maxLength={6}
                                    value={pin}
                                    onChange={(event) =>
                                        setPin(event.target.value)
                                    }
                                />
                            </label>
                            <label className="grid">
                                Confirm new PIN
                                <input
                                    className={input}
                                    type="password"
                                    inputMode="numeric"
                                    autoComplete="new-password"
                                    maxLength={6}
                                    value={confirmation}
                                    onChange={(event) =>
                                        setConfirmation(event.target.value)
                                    }
                                />
                            </label>
                            <Button
                                type="button"
                                disabled={
                                    busy ||
                                    !/^\d{6}$/.test(pin) ||
                                    pin !== confirmation
                                }
                                onClick={() =>
                                    void action('pin', {
                                        pin,
                                        pin_confirmation: confirmation,
                                    })
                                }
                            >
                                Create / reset workspace PIN
                            </Button>
                        </div>
                    )}
                    {status?.configured && !status.unlocked && !reset && (
                        <div className="flex flex-wrap gap-3">
                            <label className="grid">
                                Six-digit PIN
                                <input
                                    className={input}
                                    type="password"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    maxLength={6}
                                    value={pin}
                                    onChange={(event) =>
                                        setPin(event.target.value)
                                    }
                                />
                            </label>
                            <Button
                                type="button"
                                disabled={busy || !/^\d{6}$/.test(pin)}
                                onClick={() => void action('unlock', { pin })}
                            >
                                Unlock protected editing
                            </Button>
                        </div>
                    )}
                    <p className="text-sm">
                        {status?.locked_until
                            ? `PIN attempt lock until ${status.locked_until}.`
                            : ''}
                    </p>
                    <details>
                        <summary>
                            Recent protection/configuration activity
                        </summary>
                        {status?.events?.map((event, index) => (
                            <p className="text-sm" key={index}>
                                {event.created_at} ?{' '}
                                {event.action.replaceAll('_', ' ')} ?{' '}
                                {event.resource} ? administrator{' '}
                                {event.actor_id}
                            </p>
                        ))}
                    </details>
                    <div className="flex flex-wrap gap-3">
                        {status?.unlocked && !lease && (
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={() =>
                                    void action('lease', {
                                        resource,
                                        operation: 'acquire',
                                        version: status.version,
                                    })
                                }
                            >
                                Start protected edit
                            </Button>
                        )}
                        {lease && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={cancel}
                            >
                                Cancel protected edit
                            </Button>
                        )}
                        {status?.configured && !lease && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setReset(!reset)}
                            >
                                Reset PIN with account confirmation
                            </Button>
                        )}
                    </div>
                </section>
                {children}
            </div>
        </EditContext.Provider>
    );
}
