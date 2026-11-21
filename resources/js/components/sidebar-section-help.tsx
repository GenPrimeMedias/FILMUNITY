import { Link, usePage } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
const sections: Record<string, string> = {
 'representative profile': 'profile', 'association management': 'association',
 'members management': 'members', 'financial management': 'finance',
};
export function SidebarSectionHelp({ label }: { label: string }) {
 const { filmunityNavigation } = usePage<{ filmunityNavigation?: { associationAdmin?: boolean; associationRepresentative?: boolean } }>().props;
 const section = sections[label.trim().toLowerCase()];
 if (!section || (!filmunityNavigation?.associationAdmin && !filmunityNavigation?.associationRepresentative)) return null;
 const title = `Help with ${label} (opens a new tab)`;
 return <Link href={`/association-help/${section}`} title={title} aria-label={title} target="_blank" rel="noopener noreferrer" className="ml-auto inline-flex size-7 shrink-0 items-center justify-center rounded-md text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sidebar-primary"><CircleHelp className="size-4" aria-hidden="true" /></Link>;
}
