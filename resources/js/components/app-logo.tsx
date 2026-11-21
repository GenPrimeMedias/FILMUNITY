import { usePage } from '@inertiajs/react';

export default function AppLogo({ compact = false }: { compact?: boolean }) {
    const { filmunityBrandAssets } = usePage<{ filmunityBrandAssets?: { full: string; mini: string } }>().props;

    return (
        <>
            <img
                className={compact ? 'h-8 w-8 shrink-0 object-contain' : 'h-10 w-[180px] shrink-0 object-contain'}
                src={filmunityBrandAssets?.[compact ? 'mini' : 'full'] ?? '/brand/filmunity-logo-transparent.png'}
                alt="FILMUNITY"
                width={compact ? 32 : 180}
                height={compact ? 32 : 40}
            />
        </>
    );
}
