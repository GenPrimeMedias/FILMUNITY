import { Head, Link } from '@inertiajs/react';
import type { WorkspaceSettings } from '@/components/association-membership-fields';
import { planName } from '@/components/payment-records';
import { Button } from '@/components/ui/button';

export default function AssociationMemberWorkspace({ membership, branding }: {
    membership: { id: string; number: string; membership_type: string; starts_at: string; ends_at: string | null; payment_id: string };
    branding: WorkspaceSettings;
}) {
    return <main className="grid min-w-0 gap-4 p-4">
        <Head title={branding.display_name} />
        <header className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-xl font-semibold tracking-tight">{branding.display_name}</h1>
            <Button asChild variant="outline"><Link href="/my-association-workspaces">My association workspaces</Link></Button>
        </header>
        <section className="grid gap-4 rounded-xl border border-t-4 bg-card p-5" style={{ borderTopColor: branding.accent }}>
            <h2 className="font-semibold">Association digital membership card</h2>
            <img src={`/membership-cards/association/${membership.id}?preview=svg`} alt="Your association digital membership card" className="w-full rounded-xl border" style={{ maxWidth: 420 }} />
            <div className="flex flex-wrap items-center gap-3"><div className="flex flex-wrap gap-2"><Button asChild><a href={`/membership-cards/association/${membership.id}`}>View membership card</a></Button><Button variant="outline" asChild><a href={`/membership-cards/association/${membership.id}?download=svg`}>Download and share card</a></Button></div></div>
        </section>
        <section className="grid gap-4 rounded-xl border border-t-4 bg-card p-5" style={{ borderTopColor: branding.accent }}>
            {branding.logo_url && <img className="size-16 rounded-lg object-contain" src={branding.logo_url} alt="" />}
            <h2 className="font-semibold">Member workspace</h2>
            {branding.description && <p className="whitespace-pre-wrap text-sm text-muted-foreground">{branding.description}</p>}
            <p className="text-sm">Your role · Member</p>
            <dl className="grid gap-3 text-sm md:grid-cols-2">
                <div><dt className="text-muted-foreground">Membership number</dt><dd>{membership.number}</dd></div>
                <div><dt className="text-muted-foreground">Type</dt><dd>{planName(membership.membership_type)}</dd></div>
                <div><dt className="text-muted-foreground">Valid from</dt><dd>{membership.starts_at}</dd></div>
                <div><dt className="text-muted-foreground">Valid until</dt><dd>{membership.ends_at ?? 'Life-Time'}</dd></div>
            </dl>
            <Button asChild className="w-fit"><Link href={`/membership-payments/${membership.payment_id}`}>My payment and receipt</Link></Button>
        </section>
    </main>;
}
AssociationMemberWorkspace.layout = { breadcrumbs: [{ title: 'My association workspaces', href: '/my-association-workspaces' }] };
