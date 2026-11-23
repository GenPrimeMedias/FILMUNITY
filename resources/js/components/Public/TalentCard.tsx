import { Link } from '@inertiajs/react';

export type PublicTalent = { name: string; username: string; photo: string | null; role: string; language: string | null; city: string | null; bio: string | null; featured_work: string | null; available: boolean };

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

export function TalentCard({ talent, onInvite, className = '' }: { talent: PublicTalent; onInvite: () => void; className?: string }) {
    return (
        <article className={`flex min-h-[305px] snap-start flex-col rounded-2xl border border-neutral-800 bg-[#141714] p-5 transition hover:-translate-y-0.5 hover:border-neutral-600 ${className}`}>
            <div className="flex items-start gap-4">
                <div className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-full border border-neutral-700 bg-neutral-800 text-lg font-semibold text-[#d9f99d]">
                    {talent.photo ? <img src={talent.photo} alt="" className="h-full w-full object-cover" /> : initials(talent.name)}
                </div>
                <div className="min-w-0">
                    <h3 className="truncate font-semibold text-white">{talent.name}</h3>
                    <p className="truncate text-sm text-neutral-400">@{talent.username}</p>
                    <span className="mt-2 inline-flex rounded-full border border-[#a3e635]/30 bg-[#a3e635]/10 px-2 py-1 text-[11px] font-medium text-[#d9f99d]">Verified Identity</span>
                </div>
            </div>
            <div className="mt-5 flex flex-wrap gap-2 text-xs text-neutral-300"><span className="rounded-full bg-neutral-800 px-2.5 py-1">{talent.role}</span>{talent.language && <span className="rounded-full bg-[#d9f99d]/15 px-2.5 py-1 text-[#d9f99d]">{talent.language}</span>}{talent.available && <span className="rounded-full bg-[#a3e635]/10 px-2.5 py-1 text-[#d9f99d]">Available</span>}</div>
            <p className="mt-4 line-clamp-2 min-h-10 text-sm leading-5 text-neutral-300">{talent.bio || 'A verified film professional on FILMUNITY.'}</p>
            <div className="mt-3 flex flex-wrap gap-3 text-xs text-neutral-500">{talent.city && <span>{talent.city}</span>}{talent.featured_work && <span className="rounded-full border border-neutral-700 px-2 py-1 text-neutral-300">{talent.featured_work}</span>}</div>
            <div className="mt-auto flex gap-2 pt-5"><Link href={`/community/@${talent.username}`} className="flex-1 rounded-full border border-neutral-700 px-3 py-2 text-center text-sm text-neutral-200 hover:border-[#d9f99d]">View Showcase</Link><button type="button" onClick={onInvite} className="flex-1 rounded-full bg-[#d9f99d] px-3 py-2 text-sm font-semibold text-neutral-950 hover:bg-[#bef264]">Invite to Project</button></div>
        </article>
    );
}
