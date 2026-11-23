import { Head, Link, usePage } from '@inertiajs/react';
import { MembershipApplicationList, type ApplicationPage, type MembershipFilters } from '@/components/membership-application-list';
import { Button } from '@/components/ui/button';

export default function MembershipApplications({ applications, filters }: { applications: ApplicationPage; filters: MembershipFilters }) {
    const { filmunityNavigation } = usePage<{ filmunityNavigation?: { canApplyForMembership?: boolean } }>().props;
    const canApply = filmunityNavigation?.canApplyForMembership !== false;
    return <main className="grid min-w-0 gap-5 p-4">
        <Head title="My Memberships" />
        <header className="flex flex-wrap items-center justify-between gap-3"><h1 className="text-xl font-semibold">My Memberships</h1>{canApply && <Button asChild><Link href="/membership-applications/apply">Apply for Membership</Link></Button>}</header>
        {!canApply && <p className="text-sm text-muted-foreground">Association representatives manage their registered association and cannot apply for another association membership. Existing history remains available below.</p>}
        <section className="grid gap-3" aria-labelledby="membership-history-title"><h2 id="membership-history-title" className="font-semibold">Membership application history</h2>
            <MembershipApplicationList key={JSON.stringify([filters, applications.current_page])} applications={applications} filters={filters} url="/membership-applications" />
        </section>
    </main>;
}
MembershipApplications.layout = { breadcrumbs: [{ title: 'My Memberships', href: '/membership-applications' }] };
