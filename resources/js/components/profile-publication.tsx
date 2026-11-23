import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowUpRight, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';

const publicationUrl = '/professional-profile/publication';

export default function ProfilePublication({
    publishedUrl,
    hasDraft,
    heroSlideCount,
}: {
    publishedUrl: string | null;
    hasDraft: boolean;
    heroSlideCount: number;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    function changePublication(unpublish = false) {
        setBusy(true);
        setError('');
        setMessage('');
        const options: NonNullable<Parameters<typeof router.post>[2]> = {
            preserveScroll: true,
            onError: (errors) => {
                const issue = errors.publication;
                setError(typeof issue === 'string' ? issue : 'Could not update publication. Try again.');
            },
            onFinish: () => setBusy(false),
        };

        if (unpublish) {
            router.delete(publicationUrl, options);
        } else {
            router.post(publicationUrl, {}, options);
        }
    }

    async function copyLink() {
        if (!publishedUrl) return;
        try {
            await navigator.clipboard.writeText(new URL(publishedUrl, window.location.origin).href);
            setMessage('Profile link copied.');
        } catch {
            setMessage('Open the public profile and copy the link from your address bar.');
        }
    }

    return (
        <section aria-labelledby="publication-heading" className="grid gap-3 rounded-xl border bg-card p-5 text-card-foreground">
            <h2 id="publication-heading" className="font-semibold">Public profile</h2>
            <p className="max-w-2xl text-sm text-muted-foreground">
                {publishedUrl
                    ? 'Anyone with your link can view your published profile. Use Publish updates after editing.'
                    : 'Publish your completed professional profile to share it with anyone.'}
            </p>
            <div className="flex flex-wrap items-center gap-2">
                {publishedUrl && (
                    <>
                        <Button asChild variant="outline" size="sm">
                            <a href={publishedUrl} target="_blank" rel="noopener">
                                View public profile <ArrowUpRight />
                            </a>
                        </Button>
                        <Button type="button" variant="outline" size="sm" onClick={() => void copyLink()}>
                            <Copy /> Copy link
                        </Button>
                    </>
                )}
                <Button type="button" size="sm" disabled={busy || !hasDraft} onClick={() => changePublication()}>
                    {publishedUrl ? 'Publish updates' : 'Publish profile'}
                </Button>
                <Button asChild variant="outline" size="sm">
                    <Link href="/dashboard?panel=profile&edit=1&step=5">
                        {heroSlideCount ? 'Edit hero slider' : 'Add hero slider'}
                    </Link>
                </Button>
                {publishedUrl && (
                    <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={() => changePublication(true)}>
                        Unpublish
                    </Button>
                )}
            </div>
            {publishedUrl && <a className="w-fit break-all text-sm text-primary underline underline-offset-4" href={publishedUrl}>{publishedUrl}</a>}
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            {message && <p role="status" className="text-sm text-muted-foreground">{message}</p>}
        </section>
    );
}
