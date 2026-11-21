import { useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

export default function ProfileHeroPreview({
    slides,
    fallback,
    autoPlay = false,
}: {
    slides?: readonly string[];
    fallback?: string;
    autoPlay?: boolean;
}) {
    const [index, setIndex] = useState(0);
    const [playbackOverride, setPlaying] = useState<boolean | null>(null);
    const [reducedMotion, setReducedMotion] = useState(true);
    const playing = playbackOverride ?? (autoPlay && !reducedMotion);
    const count = slides?.length ?? 0;
    const current = count > 0 ? index % count : 0;
    const image = slides?.[current] || fallback;

    useEffect(() => {
        if (!autoPlay) return;

        const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
        const updatePreference = () => setReducedMotion(preference.matches);
        updatePreference();
        preference.addEventListener('change', updatePreference);

        return () => preference.removeEventListener('change', updatePreference);
    }, [autoPlay]);

    useEffect(() => {
        if (!playing || count < 2) return;

        const timer = window.setInterval(() => {
            setIndex((position) => (position + 1) % count);
        }, 6000);

        return () => window.clearInterval(timer);
    }, [playing, count]);

    function move(offset: number) {
        setPlaying(false);
        setIndex((position) => (position + offset + count) % count);
    }

    if (!image) return null;

    return (
        <div className="w-full min-w-0 max-w-2xl overflow-hidden rounded-lg border bg-background text-foreground" role="group" aria-label="Hero slider preview">
            <img className="block aspect-video max-h-64 w-full object-cover sm:aspect-3/1" src={image} alt={count ? `Hero slide ${current + 1} of ${count}` : 'Profile banner preview'} />
            {count > 1 && (
                <div className="flex flex-wrap items-center justify-center gap-2 border-t p-2">
                    <Button type="button" variant="outline" size="icon" className="size-8" aria-label="Previous slide" onClick={() => move(-1)}>
                        <ChevronLeft />
                    </Button>
                    <span className="min-w-12 text-center text-sm tabular-nums" aria-live={playing ? 'off' : 'polite'}>
                        {current + 1} / {count}
                    </span>
                    <Button type="button" variant="outline" size="icon" className="size-8" aria-label="Next slide" onClick={() => move(1)}>
                        <ChevronRight />
                    </Button>
                    <Button type="button" variant="outline" size="sm" onClick={() => setPlaying(!playing)}>
                        {playing ? 'Pause' : 'Play'}
                    </Button>
                </div>
            )}
        </div>
    );
}
