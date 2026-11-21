import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Query = Record<string, string | number | boolean | null | undefined>;
type Options<T> = { enabled?: boolean; valid?: (data: T) => boolean; delay?: number; textKeys?: (keyof T)[]; resetKeys?: string[] };
/** Read-only list filtering. Own requests preserve focus/state; other navigation cancels queued work. */
export function useLiveFilters<T extends Record<string, string | boolean>>(url: string, initial: T, query: (data: T) => Query, options: Options<T> = {}) {
    const page = usePage();
    const [data, setValues] = useState<T>(initial);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const values = useRef(data);
    const config = useRef({ url, initial, query, options });
    config.current = { url, initial, query, options };
    const pending = useRef<ReturnType<typeof setTimeout> | null>(null);
    const cancellation = useRef<(() => void) | null>(null);
    const revision = useRef(0);
    const dirty = useRef(false);
    const dispatching = useRef(false);
    const mounted = useRef(true);
    const invalidate = () => {
        revision.current++;
        if (pending.current !== null) clearTimeout(pending.current);
        pending.current = null;
        cancellation.current?.(); cancellation.current = null;
    };
    const send = (next: T, ticket: number) => {
        pending.current = null;
        const current = config.current;
        if (ticket !== revision.current || current.options.enabled === false || current.options.valid?.(next) === false) return;
        const params = { ...current.query(next) };
        for (const key of current.options.resetKeys ?? ['page', 'ledger_page']) delete params[key];
        dispatching.current = true;
        router.get(current.url, params, {
            preserveState: true, preserveScroll: true, replace: true, showProgress: false,
            onCancelToken: (token) => { if (ticket === revision.current) cancellation.current = () => token.cancel(); },
            onStart: () => { if (mounted.current && ticket === revision.current) setProcessing(true); },
            onError: (messages) => { if (mounted.current && ticket === revision.current) setErrors(messages as Record<string, string>); },
            onFinish: () => {
                if (ticket !== revision.current) return;
                dirty.current = false; cancellation.current = null;
                if (mounted.current) setProcessing(false);
            },
        });
        dispatching.current = false;
    };
    function setData<K extends keyof T>(key: K, value: T[K]) {
        const next = { ...values.current, [key]: value };
        invalidate(); dirty.current = true; values.current = next;
        setValues(next); setErrors({}); setProcessing(false);
        const delay = (options.textKeys ?? (['q', 'proof_q'] as (keyof T)[])).includes(key) && value !== '' ? (options.delay ?? 300) : 0;
        const ticket = revision.current;
        pending.current = setTimeout(() => send(next, ticket), delay);
    }
    function submit() {
        invalidate(); dirty.current = true;
        send(values.current, revision.current);
    }
    const initialKey = JSON.stringify(initial);
    useEffect(() => {
        if (!dirty.current) { values.current = config.current.initial; setValues(config.current.initial); }
    }, [initialKey, page.url, page.props]);
    useEffect(() => {
        mounted.current = true;
        const external = () => {
            if (dispatching.current) return;
            invalidate(); dirty.current = false;
            if (mounted.current) setProcessing(false);
        };
        const remove = router.on('before', external);
        window.addEventListener('popstate', external);
        return () => { mounted.current = false; invalidate(); remove(); window.removeEventListener('popstate', external); };
    }, []);
    const params = () => config.current.query(config.current.options.valid?.(values.current) === false ? config.current.initial : values.current);
    return { data, setData, submit, processing, errors, params };
}
