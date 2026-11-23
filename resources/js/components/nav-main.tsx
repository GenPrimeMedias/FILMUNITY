import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, type LucideIcon } from 'lucide-react';
import * as React from 'react';
import { SidebarSectionHelp } from '@/components/sidebar-section-help';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export type NavEntry = Omit<NavItem, 'href'> & {
    href?: NavItem['href'];
    comingSoon?: boolean;
};

type NavMainProps = {
    items: NavEntry[];
    label?: string;
    icon?: LucideIcon;
    groupKey: string;
    expandedGroup: string | null;
    onToggle: (groupKey: string) => void;
};

export function NavMain({
    items,
    label = 'Platform',
    icon: GroupIcon,
    groupKey,
    expandedGroup,
    onToggle,
}: NavMainProps) {
    const { isCurrentUrl } = useCurrentUrl();
    const { url, props } = usePage();
    const { isMobile, setOpenMobile, state } = useSidebar();
    const rootRef = React.useRef<HTMLDivElement>(null);
    const expanded = expandedGroup === groupKey;
    const panelId = `sidebar-panel-${groupKey}`;
    const [path, query] = url.split('?');
    const activePanel = new URLSearchParams(query).get('panel');

    React.useEffect(() => {
        if (!expanded || isMobile || state !== 'collapsed') return;
        const dismiss = (event: PointerEvent) => {
            const target = event.target as HTMLElement;
            if (target.closest('[data-sidebar-group-trigger]')) return;
            if (!rootRef.current?.contains(target)) onToggle(groupKey);
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onToggle(groupKey);
        };
        document.addEventListener('pointerdown', dismiss);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('pointerdown', dismiss);
            document.removeEventListener('keydown', escape);
        };
    }, [expanded, groupKey, isMobile, onToggle, state]);

    function isActive(href: NavItem['href']) {
        const target = typeof href === 'string' ? href : href.url;
        const [targetPath, targetQuery] = target.split('?');
        if (targetPath === '/dashboard')
            return (
                path === targetPath &&
                activePanel === new URLSearchParams(targetQuery).get('panel')
            );
        if (
            targetPath.startsWith('/association-finance/') &&
            targetPath.endsWith('/tax')
        )
            return path === targetPath || path.startsWith(`${targetPath}/`);
        if (
            targetPath.startsWith('/association-finance/') &&
            targetPath.endsWith('/billing')
        )
            return (
                path === targetPath &&
                (new URLSearchParams(targetQuery).get('scope') ===
                    'corrections') ===
                    (new URLSearchParams(query).get('scope') === 'corrections')
            );
        if (targetPath.startsWith('/association-finance/')) {
            if (path.endsWith('/billing') || /\/tax(?:\/|$)/.test(path))
                return false;
            const financeRoot = path.split('/').slice(0, 3).join('/');
            const activeTab = path.endsWith('/masters')
                ? 'accounts'
                : new URLSearchParams(query).get('tab') || 'overview';
            return (
                targetPath === financeRoot &&
                (new URLSearchParams(targetQuery).get('tab') || 'overview') ===
                    activeTab
            );
        }
        if (targetPath === '/membership-renewals')
            return (
                path.startsWith('/membership-renewals') &&
                props.isOwner !== true
            );
        if (targetPath === '/association-members') {
            const renewalTarget =
                new URLSearchParams(targetQuery).get('view') === 'renewals';
            if (
                path.startsWith('/membership-renewals/') &&
                props.isOwner === true
            )
                return renewalTarget;
            return (
                path === targetPath &&
                renewalTarget ===
                    (new URLSearchParams(query).get('view') === 'renewals')
            );
        }
        const paymentDetail = path.startsWith('/membership-payments/');
        if (targetPath === '/association-payments')
            return (
                path === targetPath ||
                (paymentDetail && props.canManage === true)
            );
        if (targetPath === '/filmunity-admin/payments')
            return (
                path === targetPath ||
                (paymentDetail &&
                    props.isAuditor === true &&
                    props.canManage !== true)
            );
        if (targetPath === '/membership-payments')
            return (
                path === targetPath ||
                (paymentDetail &&
                    props.canManage !== true &&
                    props.isAuditor !== true)
            );
        if (targetPath === '/my-association-workspaces')
            return (
                path === targetPath ||
                path.startsWith('/association-member-workspaces/')
            );
        if (targetPath === '/membership-applications/apply')
            return path === targetPath;
        if (targetPath === '/membership-applications')
            return (
                path === targetPath ||
                (path === '/dashboard' && activePanel === 'applications')
            );
        if (
            targetPath === '/association-registration/review' ||
            targetPath === '/association-membership-review'
        )
            return path === targetPath;
        if (
            targetPath.endsWith('/setup') &&
            targetPath.startsWith('/association-workspaces/')
        )
            return (
                path.startsWith('/association-workspaces/') &&
                path.endsWith('/setup')
            );
        if (targetPath === '/association-workspaces')
            return (
                path === targetPath ||
                (path.startsWith(`${targetPath}/`) && !path.endsWith('/setup'))
            );
        if (targetPath === '/filmunity-admin') return path === targetPath;
        if (targetPath.startsWith('/association-board/')) {
            return (
                path === targetPath &&
                (new URLSearchParams(targetQuery).get('view') || '') ===
                    (new URLSearchParams(query).get('view') || '')
            );
        }
        return isCurrentUrl(href);
    }

    const groupHasActiveItem = items.some(
        (item) => !item.comingSoon && item.href && isActive(item.href),
    );
    const closeMobileAfterNavigation = () => {
        if (isMobile) setOpenMobile(false);
        if (!isMobile && state === 'collapsed' && expanded) {
            onToggle(groupKey);
        }
    };
    const toggleButton = (compact = false) => (
        <button
            type="button"
            aria-expanded={expanded}
            aria-controls={panelId}
            aria-label={`${expanded ? 'Collapse' : 'Expand'} ${label}`}
            data-sidebar-group-trigger="true"
            title={compact ? label : undefined}
            onClick={() => onToggle(groupKey)}
            onMouseEnter={() => {
                if (
                    compact &&
                    !expanded &&
                    !isMobile &&
                    state === 'collapsed'
                ) {
                    onToggle(groupKey);
                }
            }}
            onFocus={() => {
                if (
                    compact &&
                    !expanded &&
                    !isMobile &&
                    state === 'collapsed'
                ) {
                    onToggle(groupKey);
                }
            }}
            className={
                compact
                    ? 'grid size-8 place-items-center rounded-md text-sidebar-foreground/80 outline-hidden transition-colors group-data-[collapsible=icon]:flex hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring'
                    : 'flex min-w-0 flex-1 items-center gap-2 rounded-md text-left text-sm font-bold tracking-tight whitespace-nowrap outline-hidden focus-visible:ring-2 focus-visible:ring-sidebar-ring'
            }
        >
            {GroupIcon && (
                <GroupIcon className="size-4 shrink-0" aria-hidden="true" />
            )}
            {!compact && <span className="truncate">{label}</span>}
            {!compact && (
                <ChevronDown
                    className={`ml-auto size-4 shrink-0 transition-transform motion-reduce:transition-none ${expanded ? 'rotate-180' : ''}`}
                    aria-hidden="true"
                />
            )}
        </button>
    );

    return (
        <div ref={rootRef} className="relative">
            <SidebarGroup className="px-2 py-0">
                <SidebarGroupLabel className="flex items-center gap-1 pr-0">
                    {toggleButton()}
                    <SidebarSectionHelp label={label} />
                </SidebarGroupLabel>
                <div className="hidden group-data-[collapsible=icon]:flex">
                    {toggleButton(true)}
                </div>
                <div
                    id={panelId}
                    role="region"
                    aria-label={`${label} navigation`}
                    hidden={!expanded}
                    className="group-data-[collapsible=icon]:absolute group-data-[collapsible=icon]:top-0 group-data-[collapsible=icon]:left-full group-data-[collapsible=icon]:z-30 group-data-[collapsible=icon]:ml-2 group-data-[collapsible=icon]:w-64 group-data-[collapsible=icon]:rounded-lg group-data-[collapsible=icon]:border group-data-[collapsible=icon]:border-sidebar-border group-data-[collapsible=icon]:bg-sidebar group-data-[collapsible=icon]:p-2 group-data-[collapsible=icon]:shadow-xl [&_[data-sidebar=menu-button]]:h-8! [&_[data-sidebar=menu-button]]:w-full! [&_[data-sidebar=menu-button]]:p-1.5!"
                >
                    <div className="mb-1 hidden items-center gap-2 px-2 text-sm font-bold tracking-tight whitespace-nowrap text-sidebar-foreground/80 group-data-[collapsible=icon]:flex">
                        {GroupIcon && (
                            <GroupIcon className="size-4" aria-hidden="true" />
                        )}
                        <span className="truncate">{label}</span>
                        <SidebarSectionHelp label={label} />
                    </div>
                    <SidebarMenu>
                        {items.map((item) => {
                            const active =
                                !item.comingSoon && item.href
                                    ? isActive(item.href)
                                    : false;
                            const key = item.href
                                ? typeof item.href === 'string'
                                    ? item.href
                                    : item.href.url
                                : item.title;
                            return (
                                <SidebarMenuItem key={key}>
                                    {item.comingSoon ? (
                                        <div
                                            className="flex min-h-8 items-center gap-2 rounded-lg px-2 py-1.5 text-[13px] font-normal whitespace-nowrap text-sidebar-foreground/65"
                                            aria-label={`${item.title}, coming soon`}
                                        >
                                            {item.icon && (
                                                <item.icon
                                                    className="size-4 shrink-0"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            <span className="min-w-0 flex-1 truncate">
                                                {item.title}
                                            </span>
                                            <span className="shrink-0 text-[10px] font-medium tracking-wide text-sidebar-primary/80 uppercase">
                                                Coming soon
                                            </span>
                                        </div>
                                    ) : (
                                        <SidebarMenuButton
                                            asChild
                                            isActive={active}
                                            className="group/nav-entry h-8 gap-2.5 rounded-lg p-1.5 text-[13px] font-normal whitespace-nowrap text-sidebar-foreground hover:bg-sidebar-accent dark:text-[#bec9ad] dark:hover:bg-[#293721] dark:hover:text-[#f5f7eb] dark:data-[active=true]:bg-[#384527] dark:data-[active=true]:font-bold dark:data-[active=true]:text-[#f5f7eb] dark:data-[active=true]:shadow-[inset_3px_0_#c4d36c]"
                                            tooltip={{ children: item.title }}
                                        >
                                            <Link
                                                href={item.href!}
                                                prefetch
                                                aria-label={item.title}
                                                aria-current={
                                                    active ? 'page' : undefined
                                                }
                                                onClick={
                                                    closeMobileAfterNavigation
                                                }
                                            >
                                                {item.icon && (
                                                    <span
                                                        aria-hidden="true"
                                                        className="grid size-5 shrink-0 place-items-center rounded-full bg-sidebar-accent text-sidebar-foreground group-data-[active=true]/nav-entry:bg-sidebar-primary group-data-[active=true]/nav-entry:text-sidebar-primary-foreground dark:bg-[#30412c] dark:text-[#e2ecb4] dark:group-data-[active=true]/nav-entry:bg-[#d4e47b] dark:group-data-[active=true]/nav-entry:text-[#182215]"
                                                    >
                                                        <item.icon className="size-3" />
                                                    </span>
                                                )}
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    )}
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                    {groupHasActiveItem && (
                        <span className="sr-only">Current section</span>
                    )}
                </div>
            </SidebarGroup>
        </div>
    );
}
