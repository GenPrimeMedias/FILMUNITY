import { Link } from '@inertiajs/react';

export function AuthGateModal({ open, onClose, authenticated }: { open: boolean; onClose: () => void; authenticated: boolean }) {
    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/70 p-5" role="dialog" aria-modal="true" aria-labelledby="invite-title">
            <div className="w-full max-w-md rounded-2xl border border-neutral-700 bg-[#141714] p-6 shadow-2xl">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs font-semibold uppercase tracking-[0.2em] text-[#d9f99d]">Project connection</p>
                        <h2 id="invite-title" className="text-xl font-semibold text-white">{authenticated ? 'Project inquiry preview' : 'Sign in to invite this talent'}</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-full border border-neutral-700 px-3 py-1 text-neutral-300 hover:border-[#d9f99d]" aria-label="Close">×</button>
                </div>
                <p className="mt-4 text-sm leading-6 text-neutral-300">{authenticated ? 'Project invitations will connect to the secure collaboration workflow in Phase 2. Your verified account is ready for that flow.' : 'Project invitations require a verified FILMUNITY account and explicit talent consent. Sign in or create a free account to continue.'}</p>
                <div className="mt-6 flex flex-wrap gap-3">
                    {authenticated ? <button type="button" onClick={onClose} className="rounded-full bg-[#d9f99d] px-4 py-2 text-sm font-semibold text-neutral-950">Got it</button> : <><Link href="/login" className="rounded-full border border-neutral-700 px-4 py-2 text-sm text-white hover:border-[#d9f99d]">Sign In to Send Request</Link><Link href="/register?intent=collaborator" className="rounded-full bg-[#d9f99d] px-4 py-2 text-sm font-semibold text-neutral-950">Create Free Account</Link></>}
                </div>
            </div>
        </div>
    );
}
