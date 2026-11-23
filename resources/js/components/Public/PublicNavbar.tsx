import { Link, usePage } from '@inertiajs/react';

type Props = { onJoin?: () => void };

export function PublicNavbar({ onJoin }: Props) {
    const { auth } = usePage<{ auth?: { user?: { id: number } | null } }>().props;

    return (
        <header className="sticky top-0 z-50 border-b border-[#d9f99d]/20 bg-[#0e110e] text-neutral-100 shadow-[0_8px_30px_-18px_rgba(217,249,157,0.9)] backdrop-blur">
            <div className="mx-auto flex w-full max-w-[1600px] items-center justify-between gap-3 px-4 py-3 sm:gap-5 sm:px-6 lg:px-10">
                <Link href="/" className="flex shrink-0 items-center rounded-lg px-1 py-1" aria-label="FILMUNITY home">
                    <img src="/brand/filmunity-logo-transparent.png" alt="FILMUNITY" className="h-8 w-auto sm:h-9" />
                </Link>
                <nav className="hidden items-center gap-7 text-sm font-medium text-neutral-300 lg:flex" aria-label="Public navigation">
                    <a href="#discover-talent" className="transition hover:text-[#d9f99d]">Discover Talent</a>
                    <a href="#associations" className="transition hover:text-[#d9f99d]">Associations &amp; Unions</a>
                    <a href="#about" className="transition hover:text-[#d9f99d]">About</a>
                    <a href="#how-it-works" className="transition hover:text-[#d9f99d]">How It Works</a>
                </nav>
                <div className="flex items-center gap-2">
                    {auth?.user ? (
                        <Link href="/dashboard" className="rounded-full bg-[#d9f99d] px-4 py-2 text-sm font-semibold text-neutral-950 transition hover:bg-[#bef264]">Open Workspace</Link>
                    ) : (
                        <>
                            <Link href="/login" className="inline-flex rounded-full border border-[#d9f99d]/70 px-3 py-2 text-xs font-medium text-[#d9f99d] transition hover:bg-[#d9f99d]/10 hover:border-[#d9f99d] sm:px-4 sm:text-sm"><span className="sm:hidden">Sign In</span><span className="hidden sm:inline">Member Sign In</span></Link>
                            <Link onClick={onJoin} href="/register?intent=talent" className="inline-flex rounded-full bg-[#d9f99d] px-3 py-2 text-xs font-semibold text-neutral-950 transition hover:bg-[#bef264] sm:px-4 sm:text-sm"><span className="sm:hidden">Join</span><span className="hidden sm:inline">Join FILMUNITY</span></Link>
                        </>
                    )}
                </div>
            </div>
        </header>
    );
}
