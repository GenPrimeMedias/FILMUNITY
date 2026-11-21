import { IndividualMembershipPanel } from '@/components/individual-membership-panel';
import { Head, Link, router, usePage } from '@inertiajs/react';
import MemberSummary, { type MemberProfileSummary } from '@/components/member-dashboard-summary';
import ProfileEditor, { type ProfessionalProfileDraft } from '@/components/professional-profile';
import ProfileOverview from '@/components/professional-profile-overview';
import ProfilePublication from '@/components/profile-publication';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

export default function Dashboard({
    professionalProfileDraft = null,
    memberProfileSummary = null,
    publishedProfileUrl = null,
}: {
    professionalProfileDraft?: ProfessionalProfileDraft | null;
    memberProfileSummary?: MemberProfileSummary | null;
    publishedProfileUrl?: string | null;
}) {
    const { props, url } = usePage();
    const user = props.auth.user;
    const query = new URLSearchParams(url.split('?')[1]);
    const panel = query.get('panel');
    const editing = query.get('edit') === '1';
    const step = query.get('step');
    const editField = query.get('focus');
    const editStep = step !== null && /^[0-6]$/.test(step) ? Number(step) : undefined;
    const profileUrl = '/dashboard?panel=profile';
    const title =
        panel === 'profile'
            ? 'Professional profile'
            : panel === 'applications'
              ? 'My applications'
              : 'Overview';

    return (
        <>
            <Head title={title} />
            <div className="flex min-w-0 flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        {title}
                    </h1>
                </div>

                {panel === 'profile' && user && (
                    editing ? (
                        <>
                            <Button asChild variant="outline" className="w-fit">
                                <Link href={profileUrl}>Return to profile overview</Link>
                            </Button>
                            <ProfileEditor
                                key={user.id}
                                userId={user.id}
                                name={user.name}
                                draft={professionalProfileDraft}
                                editStep={editStep}
                                editField={editField}
                                onExit={() => router.visit(profileUrl)}
                            />
                        </>
                    ) : (
                        <>
                            <ProfilePublication
                                publishedUrl={publishedProfileUrl}
                                hasDraft={professionalProfileDraft !== null}
                                heroSlideCount={professionalProfileDraft?.profile.heroSlides?.length ?? 0}
                            />
                            <ProfileOverview
                                accountName={user.name}
                                email={user.email}
                                draft={professionalProfileDraft}
                            />
                        </>
                    )
                )}

                {panel === 'applications' && (
                    <section className="grid gap-2 rounded-xl border border-sidebar-border bg-card p-5 text-card-foreground">
                        <h2 className="font-medium">Association applications</h2>
                        <p className="text-sm text-muted-foreground">
                            Register an association or union and track its review status.
                        </p>
                        <Button asChild className="w-fit">
                            <Link href="/association-registration">
                                {typeof props.filmunityNavigation === 'object'
                                    && props.filmunityNavigation !== null
                                    && 'canApplyForAssociation' in props.filmunityNavigation
                                    && props.filmunityNavigation.canApplyForAssociation === true
                                    ? 'Association registration'
                                    : 'View application history'}
                            </Link>
                        </Button>
                    </section>
                )}

                {panel !== 'profile' && panel !== 'applications' && user && (
                    <><MemberSummary
                        accountName={user.name}
                        photo={typeof props.professionalProfileAvatar === 'string' ? props.professionalProfileAvatar : user.avatar}
                        summary={memberProfileSummary}
                    /><IndividualMembershipPanel /></>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
