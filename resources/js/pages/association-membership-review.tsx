import { Head, Link } from '@inertiajs/react';
import { MembershipApplicationList, type ApplicationPage, type MembershipFilters } from '@/components/membership-application-list';
import { Button } from '@/components/ui/button';

const queueUrl = '/association-membership-review';

export default function AssociationMembershipReview({ applications, associationFilter, filters }: {
    applications: ApplicationPage;
    filters: MembershipFilters;
    associationFilter: string | null;
}) {
    return (
        <main className="grid w-full min-w-0 gap-5 p-4">
            <Head title="Membership applications" />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">Membership applications</h1>
                <div className="flex flex-wrap gap-2">
                    {associationFilter && <Button variant="outline" asChild><Link href={queueUrl}>All workspaces</Link></Button>}
                    <Button variant="outline" asChild><Link href={associationFilter ? `/association-workspaces/${associationFilter}` : '/association-workspaces'}>Association dashboard</Link></Button>
                </div>
            </div>
            <MembershipApplicationList key={JSON.stringify([associationFilter, filters, applications.current_page])}
                applications={applications} filters={filters} association={associationFilter} url={queueUrl} reviewing />
        </main>
    );
}

AssociationMembershipReview.layout = {
    breadcrumbs: [{ title: 'Membership applications', href: queueUrl }],
};
