import { router } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';

type Filters = { q: string; role: string; language: string; available: boolean };
type HeroSlide = { title: string; image: string };

const slides: HeroSlide[] = [
    { title: 'Showcase Your Craft. Build With Clarity.', image: '/images/hero/slide-1.png' },
    { title: 'One Verified Identity. Across Every Production.', image: '/images/hero/slide-2.png' },
    { title: 'Find Trusted Film Crew. Connect Safely.', image: '/images/hero/slide-3.png' },
    { title: 'Run Your Association. With Financial Control.', image: '/images/hero/slide-4.png' },
    { title: 'Own Your Credits. Control Your Consent.', image: '/images/hero/slide-5.png' },
];

const fallbackBackground = 'radial-gradient(circle at 30% 40%, #24301c 0%, #0e110e 75%)';

export function HeroSplitSlider({ filters, roles, languages }: { filters: Filters; roles: string[]; languages: string[] }) {
    const [slide, setSlide] = useState(0);
    const [form, setForm] = useState(filters);
    const [paused, setPaused] = useState(false);
    const touchStart = useRef<number | null>(null);
    const hasFilters = Boolean(form.q || form.role || form.language || form.available);

    useEffect(() => {
        if (paused) return;
        const timer = window.setInterval(() => setSlide((current) => (current + 1) % slides.length), 6000);
        return () => window.clearInterval(timer);
    }, [paused]);

    useEffect(() => {
        const listener = (event: KeyboardEvent) => {
            if (event.key === 'ArrowLeft') setSlide((current) => (current + slides.length - 1) % slides.length);
            if (event.key === 'ArrowRight') setSlide((current) => (current + 1) % slides.length);
        };
        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
    }, []);

    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get('/', { q: form.q || undefined, role: form.role || undefined, language: form.language || undefined, available: form.available ? '1' : undefined }, { preserveState: true, preserveScroll: true, only: ['talents', 'filters', 'totalTalentsCount'] });
        document.querySelector('#discover-talent')?.scrollIntoView({ behavior: 'smooth' });
    };

    const move = (direction: 1 | -1) => setSlide((current) => (current + direction + slides.length) % slides.length);

    return (
        <section className="relative flex min-h-[calc(100vh-64px)] w-full items-center overflow-hidden bg-[#0e110e]" onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} onTouchStart={(event) => { touchStart.current = event.touches[0]?.clientX ?? null; }} onTouchEnd={(event) => { if (touchStart.current === null) return; const delta = event.changedTouches[0]?.clientX - touchStart.current; if (Math.abs(delta) > 45) move(delta < 0 ? 1 : -1); touchStart.current = null; }}>
            <div className="absolute inset-0 z-0" style={{ backgroundImage: fallbackBackground }} aria-hidden="true">
                {slides.map((item, index) => (
                    <img key={item.image} src={item.image} alt="" aria-hidden="true" className={`absolute inset-0 h-full w-full object-cover object-center transition-all duration-1000 ease-in-out ${index === slide ? 'scale-100 opacity-100' : 'scale-105 opacity-0'}`} onError={(event) => { event.currentTarget.style.display = 'none'; }} />
                ))}
            </div>
            <div className="pointer-events-none absolute inset-0 z-10 bg-gradient-to-r from-[#0e110e]/35 via-[#121810]/15 to-[#0e110e]/30 sm:from-[#0e110e]/55 sm:via-[#121810]/25 sm:to-[#0e110e]/40" />
            <div className="pointer-events-none absolute inset-0 z-10 bg-gradient-to-b from-[#0e110e]/5 via-transparent to-[#0e110e]/35 sm:from-[#0e110e]/10 sm:to-[#0e110e]/55" />

            <div className="relative z-20 mx-auto grid w-full max-w-7xl grid-cols-1 items-end gap-10 px-6 py-12 lg:grid-cols-12 lg:gap-12 lg:px-8">
                <div className="flex flex-col justify-end space-y-8 lg:col-span-7 lg:pb-32">
                    <div>
                        <p className="mb-5 inline-flex rounded-full border border-[#d9f99d]/30 bg-[#d9f99d]/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-[#d9f99d]">A trusted home for film communities</p>
                        <div aria-live="polite"><h1 className="max-w-2xl text-4xl font-bold leading-[1.08] tracking-tight text-white sm:text-5xl xl:text-6xl">{slides[slide].title}</h1><p className="mt-6 max-w-2xl text-base leading-7 text-neutral-300 sm:text-lg">Discover verified technicians, artists, and creators across Tamil, Malayalam, Telugu, Kannada, and global cinema - or register your association to manage memberships, governance, and finances safely through one trusted platform.</p></div>
                        <div className="mt-8 flex flex-wrap gap-3"><a href="/register?intent=talent" className="rounded-full bg-[#d9f99d] px-5 py-3 text-sm font-semibold text-neutral-950 transition hover:bg-[#bef264]">Join as a Film Professional</a><a href="/register?intent=association" className="rounded-full border border-white/20 bg-black/30 px-5 py-3 text-sm font-semibold text-white backdrop-blur-sm transition hover:border-[#d9f99d]/60">Register Your Association</a></div>
                    </div>
                    <div className="flex items-center gap-3 lg:absolute lg:bottom-8 lg:right-8"><button type="button" onClick={() => move(-1)} aria-label="Previous slide" className="grid size-10 place-items-center rounded-full border border-white/25 bg-black/25 text-xl text-white transition hover:border-[#d9f99d]">&lt;</button><div className="flex items-center gap-2">{slides.map((item, index) => <button type="button" key={item.image} aria-label={`Go to slide ${index + 1}`} onClick={() => setSlide(index)} className={`h-2 rounded-full transition-all duration-300 ${index === slide ? 'w-8 bg-[#d9f99d]' : 'w-2 bg-white/40 hover:bg-white/70'}`} />)}</div><span className="text-xs text-neutral-300">{slide + 1} of {slides.length}</span><button type="button" onClick={() => move(1)} aria-label="Next slide" className="grid size-10 place-items-center rounded-full border border-white/25 bg-black/25 text-xl text-white transition hover:border-[#d9f99d]">&gt;</button></div>
                </div>

                <div className="relative z-30 w-full lg:absolute lg:bottom-4 lg:left-8 lg:right-auto lg:w-auto"><div className="w-full max-w-2xl rounded-2xl border border-[#d9f99d]/25 bg-[#121711]/90 p-4 shadow-[0_24px_60px_rgba(0,0,0,0.65)] backdrop-blur-xl sm:p-5"><div className="flex flex-wrap items-center gap-3"><p className="mr-auto text-sm font-semibold text-white">Find the right craft for your next project</p><form className="grid w-full grid-cols-2 gap-2 lg:flex lg:w-auto lg:flex-nowrap lg:items-center" onSubmit={search}><input value={form.q} onChange={(event) => setForm({ ...form, q: event.target.value })} placeholder="Name, @username, or credit..." className="col-span-2 w-full min-w-0 rounded-xl border border-neutral-700 bg-[#0e110e] px-3 py-2.5 text-sm text-white outline-none focus:border-[#d9f99d] lg:col-span-1 lg:w-56"/><select value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value })} className="w-full rounded-xl border border-neutral-700 bg-[#0e110e] px-3 py-2.5 text-sm text-white"><option value="">Role / Craft</option>{roles.map((role) => <option key={role}>{role}</option>)}</select><select value={form.language} onChange={(event) => setForm({ ...form, language: event.target.value })} className="w-full rounded-xl border border-neutral-700 bg-[#0e110e] px-3 py-2.5 text-sm text-white"><option value="">Language</option>{languages.map((language) => <option key={language}>{language}</option>)}</select><button className="col-span-2 justify-self-start rounded-full bg-[#d9f99d] px-4 py-2.5 text-sm font-semibold text-neutral-950 transition hover:bg-[#bef264] lg:col-span-1">Search</button>{hasFilters && <button type="button" onClick={() => { setForm({ q: '', role: '', language: '', available: false }); router.get('/', {}, { preserveState: true, preserveScroll: true }); }} className="col-span-2 justify-self-start text-sm text-neutral-400 underline hover:text-white lg:col-span-1">Reset</button>}</form><label className="flex w-full items-center gap-2 text-xs text-neutral-300"><input type="checkbox" checked={form.available} onChange={(event) => setForm({ ...form, available: event.target.checked })} className="size-4 accent-[#d9f99d]"/>Available for upcoming projects</label></div></div></div>
            </div>
        </section>
    );
}
