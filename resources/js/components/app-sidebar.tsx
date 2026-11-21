import { Link, usePage } from '@inertiajs/react';
import { BriefcaseBusiness, ClipboardCheck, ClipboardList, CreditCard, FileText, LayoutGrid, Settings, Shield, UserRound, Users } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard?view=member',
        icon: LayoutGrid,
    },
    {
        title: 'My Memberships',
        href: '/membership-applications',
        icon: FileText,
    },
    { title: 'Apply for Membership', href: '/membership-applications/apply', icon: FileText },
    { title: 'Memberships and renewals', href: '/membership-renewals', icon: FileText },
    { title: 'My payments', href: '/membership-payments', icon: CreditCard },
    {
        title: 'Professional profile',
        href: '/dashboard?panel=profile',
        icon: UserRound,
    },
];

export function AppSidebar() {
    const { state, isMobile } = useSidebar();
    const { filmunityNavigation } = usePage<{
        filmunityNavigation?: { associationRepresentative?: boolean; canUsePersonalMemberships?: boolean; canApplyForMembership?: boolean; platformAdmin: boolean; associationAdmin: boolean; associationMember?: boolean; associationWorkspaceId?: string };
    }>().props;

    const financePath = usePage().url.split('?')[0];
    const financeRoot = financePath.startsWith('/association-finance/') ? financePath.split('/').slice(0, 3).join('/') : (filmunityNavigation?.associationWorkspaceId ? `/association-finance/${filmunityNavigation.associationWorkspaceId}` : '/association-finance');
    const items = [...mainNavItems];
    if (filmunityNavigation?.platformAdmin) {
        items.push(
            { title: 'Association review', href: '/association-registration/review', icon: ClipboardCheck },
            { title: 'FILMUNITY Admin', href: '/filmunity-admin', icon: Shield },
            { title: 'Registered accounts', href: '/filmunity-admin/members', icon: Users },
            { title: 'Payment audit', href: '/filmunity-admin/payments', icon: CreditCard },
        );
    }
    if (filmunityNavigation?.associationAdmin) {
        items.push(
            { title: 'Association dashboard', href: '/association-workspaces', icon: BriefcaseBusiness },
            { title: 'Membership applications', href: '/association-membership-review', icon: ClipboardList },
            { title: 'Application Payments', href: '/association-payments', icon: CreditCard },
            { title: 'Members', href: '/association-members', icon: Users },
            { title: 'Members renewal', href: '/association-members?view=renewals', icon: FileText },
            ...['Overview', 'Billing', 'Tax', 'Corrections', 'Vouchers', 'Accounts', 'Reports', 'Ledger', 'Settings'].map((name) => ({ title: name === 'Overview' ? 'Finance overview' : name === 'Billing' ? 'Invoices and settlements' : name === 'Tax' ? 'GST / TDS billing' : name === 'Corrections' ? 'Credit and debit notes' : name, href: name === 'Tax' ? `${financeRoot}/tax` : name === 'Billing' ? `${financeRoot}/billing` : name === 'Corrections' ? `${financeRoot}/billing?scope=corrections` : `${financeRoot}?tab=${name.toLowerCase()}`, icon: CreditCard })),
        );
    }

    if (filmunityNavigation?.associationWorkspaceId) {
        items.push({
            title: 'Workspace setup',
            href: `/association-workspaces/${filmunityNavigation.associationWorkspaceId}/setup`,
            icon: Settings,
        });
    }

    if (filmunityNavigation?.associationMember && !filmunityNavigation?.associationRepresentative) {
        items.push({ title: 'My association workspaces', href: '/my-association-workspaces', icon: BriefcaseBusiness });
    }

    const pathOf = (item: NavItem) => (typeof item.href === 'string' ? item.href : item.href.url).split('?')[0];
    const isPlatform = (item: NavItem) => pathOf(item).startsWith('/filmunity-admin') || pathOf(item) === '/association-registration/review';
    const isAssociation = (item: NavItem) => ['/association-workspaces', '/association-membership-review', '/association-payments', '/association-members'].some((prefix) => pathOf(item) === prefix || pathOf(item).startsWith(`${prefix}/`));
    const representative = filmunityNavigation?.associationRepresentative === true;
    const isFinance = (item: NavItem) => pathOf(item).startsWith('/association-finance');
    const personalItems = items.filter((item) => !isFinance(item) && (!representative || pathOf(item) === '/dashboard') && !isPlatform(item) && !isAssociation(item) && !(pathOf(item) === '/membership-applications/apply' && filmunityNavigation?.canApplyForMembership === false));
    const associationItems = items.filter((item) => isAssociation(item));
    const associationSettings = associationItems.filter((item) => pathOf(item).startsWith('/association-workspaces'));
    const memberManagement = associationItems.filter((item) => !pathOf(item).startsWith('/association-workspaces'));
    const platformItems = items.filter(isPlatform);
    const financeItems = items.filter(isFinance);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="h-[72px] shrink-0 justify-center">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" className="h-12 justify-center" tooltip="FILMUNITY" asChild>
                            <Link href={dashboard()} prefetch aria-label="FILMUNITY dashboard">
                                <AppLogo compact={state === 'collapsed' && !isMobile} />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={personalItems} label={representative ? 'Representative profile' : 'My account'} />
                {associationSettings.length > 0 && <div className="mt-3 border-t border-sidebar-border pt-3"><NavMain items={associationSettings} label="Association Management" /></div>}
                {memberManagement.length > 0 && <div className="mt-3 border-t border-sidebar-border pt-3"><NavMain items={memberManagement} label="Members Management" /></div>}
                {financeItems.length > 0 && <div className="mt-3 border-t border-sidebar-border pt-3"><NavMain items={financeItems} label="Financial Management" /></div>}
                {platformItems.length > 0 && <div className="mt-3 border-t border-sidebar-border pt-3"><NavMain items={platformItems} label="Platform administration" /></div>}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
