import { useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import { Button } from '@/components/ui/button';

type Identity = { number: string; joined_at: string; status: string; has_passport: boolean; card_url: string; public_profile_url: string | null; verification_url: string };
export function PassportPhotoUpload() {
    const { filmunityMembership } = usePage<{ filmunityMembership?: Identity | null }>().props;
    const form = useForm({ passport: null as File | null });
    const input = useRef<HTMLInputElement>(null);
    return <section className="grid gap-3 rounded-xl border border-t-4 border-t-[#b9d66b] bg-card p-4 text-card-foreground" aria-label="Passport photo for membership cards">
        <h3 className="font-semibold">Passport photo for digital membership cards</h3>
        <p className="text-sm text-muted-foreground">Use a clear portrait at 3:4 ratio, at least 300 × 400 px, JPEG/PNG/WebP, up to 2 MB. This is separate from your public profile photo and is not published on verification pages.</p>
        <p className="text-sm">{filmunityMembership?.has_passport ? 'A passport photo is saved.' : 'No passport photo saved yet.'}</p>
        <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => form.setData('passport', event.target.files?.[0] ?? null)} />
        {Object.values(form.errors).map((error, index) => <p key={index} role="alert" className="text-sm text-destructive">{error}</p>)}
        <Button type="button" className="w-fit" disabled={!form.data.passport || form.processing} onClick={() => form.post('/membership-cards/passport-photo', { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); if (input.current) input.current.value = ''; } })}>Save passport photo</Button>
    </section>;
}
export function IndividualMembershipPanel() {
    const { filmunityMembership: membership } = usePage<{ filmunityMembership?: Identity | null }>().props;
    if (!membership) return null;
    return <section className="grid gap-4 rounded-xl border border-t-4 border-t-[#b9d66b] bg-card p-5 text-card-foreground">
        <h2 className="font-semibold">FILMUNITY individual membership</h2><div className="flex flex-wrap items-center gap-3"><strong>{membership.number}</strong><span className="rounded-full bg-[#b9d66b] px-3 py-1 text-xs font-semibold text-[#172115]">{membership.status}</span></div>
        <p className="text-sm text-muted-foreground">Member since · {membership.joined_at}</p>
        <img src="/membership-cards/filmunity?preview=svg" alt="Your FILMUNITY digital membership card with passport photo and verification QR" className="w-full rounded-xl border" style={{ maxWidth: 420 }} />
        <div className="flex flex-wrap items-center gap-3"><div className="grid gap-3"><p className="text-sm">Your digital card has a live-status verification QR.</p><div className="flex flex-wrap gap-2"><Button asChild size="sm"><a href={membership.card_url}>View card</a></Button><Button variant="outline" size="sm" asChild><a href={`${membership.card_url}?download=svg`}>Download card</a></Button></div></div></div>
        {membership.public_profile_url ? <section className="grid gap-3 rounded-lg border p-4"><h3 className="font-semibold">Share your public professional profile</h3><a href={membership.public_profile_url} className="break-all text-sm underline">{membership.public_profile_url}</a><img className="size-32 rounded-md bg-white p-2" src="/membership-cards/filmunity?qr=profile" alt="Public professional profile QR" /><Button variant="outline" className="w-fit" asChild><a href="/membership-cards/filmunity?qr=profile" download="filmunity-public-profile-qr.svg">Download profile QR</a></Button><Button type="button" variant="outline" className="w-fit" onClick={() => { void navigator.clipboard.writeText(membership.public_profile_url ?? '').catch(() => { window.prompt('Copy your public profile link', membership.public_profile_url ?? ''); }); }}>Copy profile URL</Button></section> : <p className="text-sm text-muted-foreground">Publish your completed professional profile to enable its share URL and profile QR.</p>}
        <details className="rounded-lg border p-3"><summary className="cursor-pointer text-sm font-medium">Update passport photo for cards</summary><div className="mt-3"><PassportPhotoUpload /></div></details>
    </section>;
}
