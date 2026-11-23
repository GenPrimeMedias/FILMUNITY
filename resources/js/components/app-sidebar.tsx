import { Link, usePage } from '@inertiajs/react';
import {
    BriefcaseBusiness,
    CalendarDays,
    CheckSquare,
    ClipboardCheck,
    ClipboardList,
    CreditCard,
    FileText,
    Gavel,
    LayoutGrid,
    Settings,
    Shield,
    UserRound,
    Users,
} from 'lucide-react';
import * as React from 'react';
import AppLogo from '@/components/app-logo';
import { NavMain, type NavEntry } from '@/components/nav-main';
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
    {
        title: 'Apply for Membership',
        href: '/membership-applications/apply',
        icon: FileText,
    },
    {
        title: 'Memberships and renewals',
        href: '/membership-renewals',
        icon: FileText,
    },
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
        filmunityNavigation?: {
            associationRepresentative?: boolean;
            canUsePersonalMemberships?: boolean;
            canApplyForMembership?: boolean;
            platformAdmin: boolean;
            associationAdmin: boolean;
            associationMember?: boolean;
            associationWorkspaceId?: string;
        };
    }>().props;

    const financePath = usePage().url.split('?')[0];
    const financeRoot = financePath.startsWith('/association-finance/')
        ? financePath.split('/').slice(0, 3).join('/')
        : filmunityNavigation?.associationWorkspaceId
          ? `/association-finance/${filmunityNavigation.associationWorkspaceId}`
          : '/association-finance';
    const items = [...mainNavItems];
    if (filmunityNavigation?.platformAdmin) {
        items.push(
            {
                title: 'Association review',
                href: '/association-registration/review',
                icon: ClipboardCheck,
            },
            {
                title: 'FILMUNITY Admin',
                href: '/filmunity-admin',
                icon: Shield,
            },
            {
                title: 'Registered accounts',
                href: '/filmunity-admin/members',
                icon: Users,
            },
            {
                title: 'Payment audit',
                href: '/filmunity-admin/payments',
                icon: CreditCard,
            },
        );
    }
    if (filmunityNavigation?.associationAdmin) {
        items.push(
            {
                title: 'Association dashboard',
                href: '/association-workspaces',
                icon: BriefcaseBusiness,
            },
            {
                title: 'Membership applications',
                href: '/association-membership-review',
                icon: ClipboardList,
            },
            {
                title: 'Application Payments',
                href: '/association-payments',
                icon: CreditCard,
            },
            { title: 'Members', href: '/association-members', icon: Users },
            {
                title: 'Members renewal',
                href: '/association-members?view=renewals',
                icon: FileText,
            },
            ...[
                'Overview',
                'Billing',
                'Tax',
                'Corrections',
                'Vouchers',
                'Accounts',
                'Reports',
                'Ledger',
                'Settings',
            ].map((name) => ({
                title:
                    name === 'Overview'
                        ? 'Finance overview'
                        : name === 'Billing'
                          ? 'Invoices and settlements'
                          : name === 'Tax'
                            ? 'GST / TDS billing'
                            : name === 'Corrections'
                              ? 'Credit and debit notes'
                              : name,
                href:
                    name === 'Tax'
                        ? `${financeRoot}/tax`
                        : name === 'Billing'
                          ? `${financeRoot}/billing`
                          : name === 'Corrections'
                            ? `${financeRoot}/billing?scope=corrections`
                            : `${financeRoot}?tab=${name.toLowerCase()}`,
                icon: CreditCard,
            })),
        );
    }

    if (filmunityNavigation?.associationWorkspaceId) {
        items.push({
            title: 'Workspace setup',
            href: `/association-workspaces/${filmunityNavigation.associationWorkspaceId}/setup`,
            icon: Settings,
        });
    }

    if (
        filmunityNavigation?.associationMember &&
        !filmunityNavigation?.associationRepresentative
    ) {
        items.push({
            title: 'My association workspaces',
            href: '/my-association-workspaces',
            icon: BriefcaseBusiness,
        });
    }

    const pathOf = (item: NavItem) =>
        (typeof item.href === 'string' ? item.href : item.href.url).split(
            '?',
        )[0];
    const isPlatform = (item: NavItem) =>
        pathOf(item).startsWith('/filmunity-admin') ||
        pathOf(item) === '/association-registration/review';
    const isAssociation = (item: NavItem) =>
        [
            '/association-workspaces',
            '/association-membership-review',
            '/association-payments',
            '/association-members',
        ].some(
            (prefix) =>
                pathOf(item) === prefix ||
                pathOf(item).startsWith(`${prefix}/`),
        );
    const representative =
        filmunityNavigation?.associationRepresentative === true;
    const isFinance = (item: NavItem) =>
        pathOf(item).startsWith('/association-finance');
    const personalItems = items.filter(
        (item) =>
            !isFinance(item) &&
            (!representative || pathOf(item) === '/dashboard') &&
            !isPlatform(item) &&
            !isAssociation(item) &&
            !(
                pathOf(item) === '/membership-applications/apply' &&
                filmunityNavigation?.canApplyForMembership === false
            ),
    );
    const associationItems = items.filter((item) => isAssociation(item));
    const associationSettings = associationItems.filter((item) =>
        pathOf(item).startsWith('/association-workspaces'),
    );
    const memberManagement = associationItems.filter(
        (item) => !pathOf(item).startsWith('/association-workspaces'),
    );
    const platformItems = items.filter(isPlatform);
    const financeItems = items.filter(isFinance);

    const profileItems: NavEntry[] = representative
        ? [
              {
                  title: 'Dashboard',
                  href: '/dashboard?view=member',
                  icon: LayoutGrid,
              },
              {
                  title: 'Professional profile',
                  href: '/dashboard?panel=profile',
                  icon: UserRound,
              },
          ]
        : personalItems;
    const boardBase = `/association-board/${filmunityNavigation?.associationWorkspaceId ?? ''}`;
    const boardItems: NavEntry[] = [
        { title: 'Board overview', href: boardBase, icon: LayoutGrid },
        {
            title: 'Board members',
            href: `${boardBase}/terms?view=members`,
            icon: Users,
        },
        {
            title: 'Positions and terms',
            href: `${boardBase}/terms?view=positions`,
            icon: Gavel,
        },
        {
            title: 'Board meetings',
            href: `${boardBase}/meetings`,
            icon: CalendarDays,
        },
        {
            title: 'Minutes and resolutions',
            href: `${boardBase}/minutes`,
            icon: FileText,
        },
        {
            title: 'Action tracker',
            href: `${boardBase}/actions`,
            icon: CheckSquare,
        },
    ];
    const electionItems: NavEntry[] = [
        { title: 'Election overview', icon: LayoutGrid, comingSoon: true },
        { title: 'Election schedule', icon: CalendarDays, comingSoon: true },
        { title: 'Positions and eligibility', icon: Gavel, comingSoon: true },
        { title: 'Electoral roll', icon: Users, comingSoon: true },
        { title: 'Nominations', icon: ClipboardList, comingSoon: true },
        { title: 'Polling and results', icon: CheckSquare, comingSoon: true },
    ];
    const groups = [
        {
            key: 'profile',
            label: representative ? 'Representative profile' : 'My account',
            icon: UserRound,
            items: profileItems,
        },
        ...(associationSettings.length > 0
            ? [
                  {
                      key: 'association',
                      label: 'Association Management',
                      icon: BriefcaseBusiness,
                      items: associationSettings,
                  },
              ]
            : []),
        ...(memberManagement.length > 0
            ? [
                  {
                      key: 'members',
                      label: 'Members Management',
                      icon: Users,
                      items: memberManagement,
                  },
              ]
            : []),
        ...(filmunityNavigation?.associationAdmin
            ? [
                  {
                      key: 'board',
                      label: 'Board Management',
                      icon: Gavel,
                      items: boardItems,
                  },
                  {
                      key: 'elections',
                      label: 'Election Management — future module',
                      icon: CheckSquare,
                      items: electionItems,
                  },
              ]
            : []),
        ...(financeItems.length > 0
            ? [
                  {
                      key: 'finance',
                      label: 'Financial Management',
                      icon: CreditCard,
                      items: financeItems,
                  },
              ]
            : []),
        ...(platformItems.length > 0
            ? [
                  {
                      key: 'platform',
                      label: 'Platform administration',
                      icon: Shield,
                      items: platformItems,
                  },
              ]
            : []),
    ];
    const [expandedGroup, setExpandedGroup] = React.useState<string | null>(
        null,
    );
    const { url } = usePage();
    React.useEffect(() => {
        if (!isMobile && state === 'collapsed') {
            setExpandedGroup(null);
            return;
        }
        const currentPath = url.split('?')[0];
        const next = currentPath.startsWith('/association-finance')
            ? 'finance'
            : currentPath.startsWith('/association-board/')
              ? 'board'
              : currentPath.startsWith('/association-workspaces') ||
                  currentPath === '/association-workspaces'
                ? 'association'
                : currentPath.startsWith('/association-members') ||
                    currentPath.startsWith('/association-membership-review') ||
                    currentPath.startsWith('/association-payments')
                  ? 'members'
                  : currentPath.startsWith('/filmunity-admin') ||
                      currentPath.startsWith('/association-registration/review')
                    ? 'platform'
                    : 'profile';
        if (groups.some((group) => group.key === next)) setExpandedGroup(next);
    }, [isMobile, state, url]);
    const toggleGroup = React.useCallback((groupKey: string) => {
        setExpandedGroup((current) => (current === groupKey ? null : groupKey));
    }, []);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="h-[72px] shrink-0 justify-center">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            className="h-12 justify-center"
                            tooltip="FILMUNITY"
                            asChild
                        >
                            <Link
                                href={dashboard()}
                                prefetch
                                aria-label="FILMUNITY dashboard"
                            >
                                <AppLogo
                                    compact={state === 'collapsed' && !isMobile}
                                />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="group-data-[collapsible=icon]:overflow-visible">
                {groups.map((group, index) => (
                    <div
                        key={group.key}
                        className={
                            index === 0
                                ? ''
                                : 'mt-3 border-t border-sidebar-border pt-3'
                        }
                    >
                        <NavMain
                            groupKey={group.key}
                            label={group.label}
                            icon={group.icon}
                            items={group.items}
                            expandedGroup={expandedGroup}
                            onToggle={toggleGroup}
                        />
                    </div>
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
