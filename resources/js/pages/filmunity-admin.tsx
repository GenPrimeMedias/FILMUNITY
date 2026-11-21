import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

export default function FilmunityAdmin({ members, pending, approved, declined }: {
    members: number;
    pending: number;
    approved: number;
    declined: number;
}) {
    const queue = '/association-registration/review';
    const cards = [
        { label: 'Registered accounts', count: members, href: '/filmunity-admin/members' },
        { label: 'Pending review', count: pending, href: `${queue}?status=pending` },
        { label: 'Approved registrations', count: approved, href: `${queue}?status=approved` },
        { label: 'Declined registrations', count: declined, href: `${queue}?status=declined` },
    ];

    return (
        <div className="grid gap-5 p-4">
            <Head title="FILMUNITY Admin" />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">FILMUNITY Admin</h1>
                <Button variant="outline" asChild><Link href="/dashboard?view=member">Member dashboard</Link></Button>
            </div>
            <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {cards.map(({ label, count, href }) => (
                    <li key={label}>
                        <Link href={href} className="grid gap-2 rounded-xl border bg-card p-5 text-card-foreground hover:border-primary focus-visible:outline-2 focus-visible:outline-ring">
                            <span className="text-sm text-muted-foreground">{label}</span>
                            <span className="text-2xl font-semibold tabular-nums">{count}</span>
                        </Link>
                    </li>
                ))}
            </ul>
            <Button className="w-fit" asChild><Link href={queue}>Association review queue</Link></Button>
        </div>
    );
}

FilmunityAdmin.layout = {
    breadcrumbs: [{ title: 'FILMUNITY Admin', href: '/filmunity-admin' }],
};
