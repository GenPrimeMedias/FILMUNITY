import { Link } from '@inertiajs/react';
import { useId, type ReactNode } from 'react';
import { Camera, ImagePlus, Pencil } from 'lucide-react';
import {
    socialPlatforms,
    validSocial,
    type ProfessionalProfileDraft,
} from '@/components/professional-profile';
import ProfileHeroPreview from '@/components/profile-hero-preview';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/profile';

const editProfileUrl = '/dashboard?panel=profile&edit=1';

function ProfileCard({
    title,
    step,
    field,
    children,
}: {
    title: string;
    step?: number;
    field?: string;
    children: ReactNode;
}) {
    const headingId = useId();

    return (
        <section className="flex h-96 min-w-0 flex-col rounded-xl border bg-card text-card-foreground" aria-labelledby={headingId}>
            <div className="flex flex-wrap items-center justify-between gap-2 shrink-0 border-b px-5 py-3">
                <h3 id={headingId} className="font-semibold">{title}</h3>
                {step !== undefined && (
                    <Button asChild variant="ghost" size="sm">
                        <Link href={`${editProfileUrl}&step=${step}${field ? `&focus=${field}` : ""}`} aria-label={`Edit ${title.toLowerCase()}`}>
                            <Pencil />
                            Edit
                        </Link>
                    </Button>
                )}
            </div>
            <div className="min-h-0 flex-1 overflow-auto p-5 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring" tabIndex={0} role="region" aria-labelledby={headingId}>
                {children}
            </div>
        </section>
    );
}

function Details({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="divide-y text-sm">
            {rows.map(([label, value]) => (
                <div key={label} className="grid gap-1 py-2 first:pt-0 last:pb-0 sm:grid-cols-[minmax(7rem,2fr)_3fr] sm:gap-4">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="min-w-0 break-words">{value || '—'}</dd>
                </div>
            ))}
        </dl>
    );
}

function ProfileLink({ href, children }: { href?: string; children: ReactNode }) {
    if (!href || !validSocial(href)) return null;

    return <a className="text-primary underline underline-offset-4" href={href}>{children}</a>;
}

export default function ProfileOverview({
    accountName,
    email,
    draft,
}: {
    accountName: string;
    email: string;
    draft: ProfessionalProfileDraft | null;
}) {
    const profile = draft?.profile;
    const name = profile?.name?.trim() || accountName;
    const initials = name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    const photo = profile?.photo;
    const username = profile?.username?.replace(/^@/, '');
    const industries = [profile?.industry, ...(profile?.additional ?? [])].filter(Boolean).join(' · ');
    const projects = profile?.portfolio?.filter((project) => project.title.trim()) ?? [];
    const featured = projects.find((project) => project.id === profile?.featuredProjectId);
    const selectedWork = featured ? [featured, ...projects.filter((project) => project.id !== featured.id)] : projects;

    return (
        <div className="grid gap-5">
            <section className="overflow-hidden rounded-xl border bg-card text-center text-card-foreground">
                <div className="relative bg-gradient-to-br from-sidebar-accent via-card to-background">
                    {profile?.banner ? (
                        <img className="block aspect-3/1 w-full object-cover lg:aspect-[6/1]" src={profile.banner} alt="" />
                    ) : (
                        <div className="h-28 sm:h-40" />
                    )}
                    <Button asChild variant="outline" size="sm" className="absolute top-3 right-3">
                        <Link href={`${editProfileUrl}&step=2&focus=banner`}>
                            <ImagePlus />
                            {profile?.banner ? 'Edit banner' : 'Add banner'}
                        </Link>
                    </Button>
                </div>
                <div className="relative -mt-12 flex flex-col items-center gap-2 px-5 pb-6">
                    <div className="relative">
                    {photo?.startsWith('data:image/') ? (
                        <img className="size-24 rounded-full border-4 border-card bg-muted object-cover" src={photo} alt="" />
                    ) : (
                        <span className="grid size-24 place-items-center rounded-full border-4 border-card bg-accent text-2xl font-semibold text-accent-foreground" aria-hidden="true">
                            {initials}
                        </span>
                    )}
                        <Button asChild size="icon" className="absolute right-0 bottom-0 size-8 rounded-full">
                            <Link href={`${editProfileUrl}&step=2&focus=photo`} aria-label="Edit profile photo" title="Edit profile photo">
                                <Camera />
                            </Link>
                        </Button>
                    </div>
                    <h2 className="break-all text-2xl font-semibold tracking-tight">{name}</h2>
                    {profile?.headline && <p className="max-w-xl break-words">{profile.headline}</p>}
                    <p className="break-all text-sm text-muted-foreground">{username ? `@${username}` : email}</p>
                    <Button asChild className="mt-2">
                        <Link href={editProfileUrl}>
                            <Pencil />
                            {draft ? 'Edit profile' : 'Set up profile'}
                        </Link>
                    </Button>
                </div>
            </section>

            <div className="grid gap-5 lg:grid-cols-2">
                {profile && (
                    <>
                        <ProfileCard title="Professional details" step={1}>
                            <Details rows={[
                                ['Display name', profile.name],
                                ['Username', username ? `@${username}` : null],
                                ['Headline', profile.headline],
                                ['Base city', profile.city],
                            ]} />
                        </ProfileCard>
                        <ProfileCard title="Industry and role" step={0}>
                            <Details rows={[['Credited role', profile.role], ['Industries', industries]]} />
                        </ProfileCard>
                        <ProfileCard title="About and links" step={3}>
                            {profile.bio && <p className="mb-4 whitespace-pre-wrap break-words text-sm">{profile.bio}</p>}
                            <Details rows={[
                                ['Specialisations', profile.specialisations],
                                ['Working languages', profile.languages],
                                ['Awards and festivals', profile.highlights],
                                ['Training', profile.training],
                                ['Self-reported memberships', profile.memberships],
                                ['Showreel', profile.showreel && validSocial(profile.showreel) ? <ProfileLink href={profile.showreel}>Watch showreel</ProfileLink> : null],
                                ['Website or social link', profile.social && validSocial(profile.social) ? <ProfileLink href={profile.social}>Visit link</ProfileLink> : null],
                            ]} />
                        </ProfileCard>
                        <ProfileCard title="Work preferences" step={1} field="preferences">
                            <Details rows={[
                                ['Availability', profile.availability],
                                ['Travel preferences', profile.travel],
                                ['Project enquiries', profile.openToEnquiries ? 'Open' : 'Closed'],
                                ['Show work preferences', profile.showWorkPreferences ? 'On' : 'Off'],
                            ]} />
                        </ProfileCard>
                        <ProfileCard title="Social media" step={3} field="social-links">
                            <Details rows={Object.entries(socialPlatforms).map(([platform, label]): [string, ReactNode] => {
                                const href = profile.socialLinks?.[platform as keyof typeof socialPlatforms];
                                return [label, href && validSocial(href) ? <ProfileLink href={href}>View {label} profile</ProfileLink> : null];
                            })} />
                        </ProfileCard>
                        <ProfileCard title="Hero slider" step={5}>
                            {profile.heroSlides?.length ? (
                                <ProfileHeroPreview slides={profile.heroSlides} autoPlay />
                            ) : <p className="text-sm text-muted-foreground">No hero slides added yet.</p>}
                        </ProfileCard>
                        <ProfileCard title="Selected work" step={4}>
                            {selectedWork.length ? (
                                <div className="grid gap-3 2xl:grid-cols-2">
                                    {selectedWork.map((project) => (
                                        <article key={project.id} className="grid min-w-0 gap-1 rounded-lg border bg-background p-4 text-sm">
                                            <h4 className="break-words font-semibold">{project.title}</h4>
                                            {project.id === featured?.id && <p className="font-medium text-primary">Featured project</p>}
                                            <p className="break-words text-muted-foreground">{[project.role, project.type, project.year].filter(Boolean).join(' · ')}</p>
                                            {project.contribution && <p className="whitespace-pre-wrap break-words">{project.contribution}</p>}
                                            <ProfileLink href={project.url}>View project</ProfileLink>
                                        </article>
                                    ))}
                                </div>
                            ) : <p className="text-sm text-muted-foreground">No projects added yet.</p>}
                        </ProfileCard>
                    </>
                )}

                <ProfileCard title="Account details">
                    <Details rows={[['Account name', accountName], ['Email address', email]]} />
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm"><Link href={edit()}>Account settings</Link></Button>
                    </div>
                </ProfileCard>
            </div>
        </div>
    );
}
