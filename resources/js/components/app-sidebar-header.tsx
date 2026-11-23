import { usePage } from '@inertiajs/react';
import { WorkflowBreadcrumbs } from '@/components/workflow-breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type HeaderPageProps = {
    auth?: {
        user?: {
            name?: string;
            avatar?: string | null;
        } | null;
    };
    professionalProfileAvatar?: string | null;
    association?: {
        id?: string;
        name?: string;
    } | null;
    associationBranding?: {
        display_name?: string | null;
        logo_url?: string | null;
    } | null;
    branding?: {
        display_name?: string | null;
        logo_url?: string | null;
    } | null;
    workspaceBranding?: {
        display_name?: string | null;
        logo_url?: string | null;
    } | null;
};

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { props, url } = usePage<HeaderPageProps>();
    const getInitials = useInitials();
    const workspaceBranding = props.associationBranding ?? props.branding ?? props.workspaceBranding;
    const path = url.split('?')[0];
    const isMemberWorkspace = path === '/dashboard'
        || path.startsWith('/membership-')
        || path === '/my-association-workspaces'
        || path.startsWith('/association-member-workspaces/');
    const memberName = props.auth?.user?.name;
    const memberAvatar = props.professionalProfileAvatar ?? props.auth?.user?.avatar;
    const associationName = isMemberWorkspace ? memberName : workspaceBranding?.display_name ?? props.association?.name;
    const logoUrl = isMemberWorkspace ? memberAvatar : workspaceBranding?.logo_url;

    return (
        <header className="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 bg-background/95 px-6 backdrop-blur supports-[backdrop-filter]:bg-background/80 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <WorkflowBreadcrumbs fallback={breadcrumbs} />
            </div>
            {associationName && (
                <div className="ml-auto flex max-w-[65vw] min-w-0 items-center gap-2 rounded-lg border border-sidebar-border/70 bg-sidebar/60 px-2 py-1.5 sm:px-3">
                    <Avatar className="size-8 border border-sidebar-border/70 bg-sidebar-accent">
                        {logoUrl && <AvatarImage src={logoUrl} alt={`${associationName} profile`} />}
                        <AvatarFallback className="bg-sidebar-accent text-xs font-semibold text-sidebar-accent-foreground">
                            {getInitials(associationName)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="hidden min-w-0 overflow-x-auto text-right leading-tight [scrollbar-width:none] sm:block">
                        <p className="whitespace-nowrap text-sm font-medium text-foreground">{associationName}</p>
                    </div>
                </div>
            )}
        </header>
    );
}
