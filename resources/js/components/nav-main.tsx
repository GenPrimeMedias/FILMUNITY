import { SidebarSectionHelp } from '@/components/sidebar-section-help';
import { Link, usePage } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({ items, label = 'Platform' }: { items: NavItem[]; label?: string }) {
    const { isCurrentUrl } = useCurrentUrl();
    const { url, props } = usePage();
    const [path, query] = url.split('?');
    const activePanel = new URLSearchParams(query).get('panel');

    function isActive(href: NavItem['href']) {
        const target = typeof href === 'string' ? href : href.url;
        const [targetPath, targetQuery] = target.split('?');
        if (targetPath === '/dashboard') {
            return (
                path === targetPath &&
                activePanel === new URLSearchParams(targetQuery).get('panel')
            );
        }

        if (targetPath.startsWith('/association-finance/') && targetPath.endsWith('/tax')) return path === targetPath || path.startsWith(targetPath + '/');
        if (targetPath.startsWith('/association-finance/') && targetPath.endsWith('/billing')) return path === targetPath && (new URLSearchParams(targetQuery).get('scope') === 'corrections') === (new URLSearchParams(query).get('scope') === 'corrections');
        if (targetPath.startsWith('/association-finance/')) {
            if (path.endsWith('/billing') || /\/tax(?:\/|$)/.test(path)) return false;
            const financeRoot = path.split('/').slice(0, 3).join('/');
            const activeTab = path.endsWith('/masters') ? 'accounts' : (new URLSearchParams(query).get('tab') || 'overview');
            return targetPath === financeRoot && (new URLSearchParams(targetQuery).get('tab') || 'overview') === activeTab;
        }
        if (targetPath === '/membership-renewals') return path.startsWith('/membership-renewals') && props.isOwner !== true;
        if (targetPath === '/association-members') {
            const renewalTarget = new URLSearchParams(targetQuery).get('view') === 'renewals';
            if (path.startsWith('/membership-renewals/') && props.isOwner === true) return renewalTarget;
            return path === targetPath && renewalTarget === (new URLSearchParams(query).get('view') === 'renewals');
        }
        const paymentDetail = path.startsWith('/membership-payments/');
        if (targetPath === '/association-payments') {
            return path === targetPath || (paymentDetail && props.canManage === true);
        }
        if (targetPath === '/filmunity-admin/payments') {
            return path === targetPath || (paymentDetail && props.isAuditor === true && props.canManage !== true);
        }
        if (targetPath === '/membership-payments') {
            return path === targetPath || (paymentDetail && props.canManage !== true && props.isAuditor !== true);
        }
        if (targetPath === '/my-association-workspaces') {
            return path === targetPath || path.startsWith('/association-member-workspaces/');
        }
        if (targetPath === '/membership-applications/apply') return path === targetPath;
        if (targetPath === '/membership-applications') {
            return path === targetPath || (path === '/dashboard' && activePanel === 'applications');
        }
        if (targetPath === '/association-registration/review' || targetPath === '/association-membership-review') {
            return path === targetPath;
        }
        if (targetPath.endsWith('/setup') && targetPath.startsWith('/association-workspaces/')) {
            return path.startsWith('/association-workspaces/') && path.endsWith('/setup');
        }
        if (targetPath === '/association-workspaces') {
            return path === targetPath || (path.startsWith(`${targetPath}/`) && !path.endsWith('/setup'));
        }
        if (targetPath === '/filmunity-admin') {
            return path === targetPath;
        }

        return isCurrentUrl(href);
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel className="flex items-center gap-1 pr-0"><span className="truncate">{label}</span><SidebarSectionHelp label={label} /></SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => {
                    const active = isActive(item.href);

                    return (
                        <SidebarMenuItem key={typeof item.href === 'string' ? item.href : item.href.url}>
                            <SidebarMenuButton
                                asChild
                                isActive={active}
                                className="group/nav-entry h-8 gap-2.5 rounded-lg p-1.5 text-sm text-sidebar-foreground hover:bg-sidebar-accent dark:text-[#bec9ad] dark:hover:bg-[#293721] dark:hover:text-[#f5f7eb] dark:data-[active=true]:bg-[#384527] dark:data-[active=true]:font-bold dark:data-[active=true]:text-[#f5f7eb] dark:data-[active=true]:shadow-[inset_3px_0_#c4d36c]"
                                tooltip={{ children: item.title }}
                            >
                                <Link href={item.href} prefetch aria-label={item.title} aria-current={active ? 'page' : undefined}>
                                    {item.icon && (
                                        <span aria-hidden="true" className="grid size-5 shrink-0 place-items-center rounded-full bg-sidebar-accent text-sidebar-foreground group-data-[active=true]/nav-entry:bg-sidebar-primary group-data-[active=true]/nav-entry:text-sidebar-primary-foreground dark:bg-[#30412c] dark:text-[#e2ecb4] dark:group-data-[active=true]/nav-entry:bg-[#d4e47b] dark:group-data-[active=true]/nav-entry:text-[#182215]">
                                            <item.icon className="size-3" />
                                        </span>
                                    )}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
