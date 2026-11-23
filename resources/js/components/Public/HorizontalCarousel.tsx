import { useRef } from 'react';
import { Link } from '@inertiajs/react';

export function HorizontalCarousel({ children, viewAllHref, viewAllLabel = 'View All', title, eyebrow }: { children: React.ReactNode; viewAllHref?: string; viewAllLabel?: string; title: string; eyebrow?: string }) {
    const track = useRef<HTMLDivElement>(null);
    const move = (direction: number) => track.current?.scrollBy({ left: direction * Math.max(280, track.current.clientWidth * 0.72), behavior: 'smooth' });

    return <div><div className="flex flex-wrap items-end justify-between gap-4"><div>{eyebrow && <p className="text-xs font-semibold uppercase tracking-[0.22em] text-[#d9f99d]">{eyebrow}</p>}<h2 className="mt-2 text-2xl font-semibold text-white sm:text-3xl">{title}</h2></div><div className="flex items-center gap-2"><button type="button" onClick={() => move(-1)} aria-label={`Previous ${title}`} className="grid size-9 place-items-center rounded-full border border-neutral-700 text-lg text-neutral-200 hover:border-[#d9f99d] hover:text-[#d9f99d]">‹</button><button type="button" onClick={() => move(1)} aria-label={`Next ${title}`} className="grid size-9 place-items-center rounded-full border border-neutral-700 text-lg text-neutral-200 hover:border-[#d9f99d] hover:text-[#d9f99d]">›</button>{viewAllHref && <Link href={viewAllHref} className="ml-2 rounded-full border border-[#d9f99d]/50 px-3 py-2 text-xs font-semibold text-[#d9f99d] hover:bg-[#d9f99d]/10">{viewAllLabel} →</Link>}</div></div><div ref={track} className="mt-7 flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth pb-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">{children}</div></div>;
}
