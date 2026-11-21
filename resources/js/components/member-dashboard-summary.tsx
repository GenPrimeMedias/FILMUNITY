import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import {
    profileCompletion,
    type ProfessionalProfileDraft,
} from '@/components/professional-profile';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { edit } from '@/routes/profile';

export type MemberProfileSummary = {
    profile: ProfessionalProfileDraft['profile'];
    hasPhoto: boolean;
};

const profileUrl = '/dashboard?panel=profile';

export default function MemberSummary({
    accountName,
    photo,
    summary,
}: {
    accountName: string;
    photo?: string | null;
    summary: MemberProfileSummary | null;
}) {
    const representativeWorkflow = usePage<{ filmunityNavigation?: { associationRepresentative?: boolean } }>().props.filmunityNavigation?.associationRepresentative === true;
    const getInitials = useInitials();
    const profile = summary?.profile;
    const name = profile?.name?.trim() || accountName;
    const metadata = [profile?.role, profile?.industry, profile?.city].filter(Boolean).join(' · ');
    const completion = summary ? profileCompletion(summary.profile, summary.hasPhoto) : 0;
    const portfolio = profile?.portfolio?.filter((project) => project.title.trim()) ?? [];
    const featured = portfolio.find((project) => project.id === profile?.featuredProjectId);
    const projects = (featured ? [featured, ...portfolio.filter((project) => project.id !== featured.id)] : portfolio).slice(0, 3);

    return (
        <div className="grid gap-4">
            <section aria-labelledby="member-name" className="grid min-w-0 gap-5 rounded-xl border bg-card p-5 text-card-foreground sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(15rem,20rem)]">
                <div className="flex min-w-0 flex-wrap items-center gap-4 sm:flex-nowrap">
                    <Avatar className="size-16 shrink-0 rounded-full">
                        <AvatarImage src={photo ?? undefined} alt="" className="object-cover" />
                        <AvatarFallback className="bg-sidebar-accent text-sidebar-accent-foreground">
                            {getInitials(name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <h2 id="member-name" className="break-words text-xl font-semibold">{name}</h2>
                        {profile?.username && <p className="break-all text-sm text-muted-foreground">@{profile.username.replace(/^@/, '')}</p>}
                        {profile?.headline && <p className="line-clamp-2 text-sm">{profile.headline}</p>}
                        {metadata && <p className="mt-1 break-words text-sm text-muted-foreground">{metadata}</p>}
                    </div>
                </div>
                <div className="grid content-center gap-2">
                    <div className="flex justify-between gap-2 text-sm">
                        <span>Profile completion</span>
                        <span className="font-semibold text-primary">{completion}%</span>
                    </div>
                    <progress className="h-2 w-full accent-primary" aria-label="Profile completion" value={completion} max={100} />
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Button asChild size="sm">
                            <Link href={profile ? profileUrl : `${profileUrl}&edit=1`}>
                                {profile ? 'View profile' : 'Set up profile'}
                                <ArrowUpRight />
                            </Link>
                        </Button>
                        {profile && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={`${profileUrl}&edit=1`}>Edit profile</Link>
                            </Button>
                        )}
                    </div>
                </div>
            </section>

            <div className="grid gap-4 lg:grid-cols-2">
                <section aria-labelledby="member-bio" className="min-w-0 rounded-xl border bg-card p-5 text-card-foreground">
                    <h2 id="member-bio" className="font-semibold">About</h2>
                    <p className="mt-2 line-clamp-3 break-words text-sm text-muted-foreground">
                        {profile?.bio || 'Add a short bio to introduce your work.'}
                    </p>
                    {profile?.openToEnquiries && (
                        <p className="mt-3 text-sm font-medium text-primary">Open to project enquiries</p>
                    )}
                    {profile?.availability && (
                        <p className="mt-1 break-words text-sm text-muted-foreground">Availability · {profile.availability}</p>
                    )}
                </section>

                <section aria-labelledby="member-work" className="min-w-0 rounded-xl border bg-card p-5 text-card-foreground">
                    <h2 id="member-work" className="font-semibold">Selected work</h2>
                    {projects.length ? (
                        <ul className="mt-2 divide-y text-sm">
                            {projects.map((project) => (
                                <li key={project.id} className="py-2 first:pt-0 last:pb-0">
                                    <p className="break-words font-medium">{project.title}</p>
                                    <p className="break-words text-muted-foreground">
                                        {[project.role, project.type, project.year].filter(Boolean).join(' · ')}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mt-2 text-sm text-muted-foreground">No projects added yet.</p>
                    )}
                </section>
            </div>

            <div className="flex flex-wrap gap-2">
                {!representativeWorkflow && (<Button asChild variant="outline" size="sm">
                    <Link href="/membership-applications">My Memberships</Link>
                </Button>)}
                <Button asChild variant="outline" size="sm">
                    <Link href={edit()}>Account settings</Link>
                </Button>
            </div>
        </div>
    );
}
