import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { WorkspaceEditProtection } from '@/components/workspace-edit-protection';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { url } = usePage();
    const path = url.split('?')[0];
    const financeMatch = path.match(/^\/association-finance\/([^/]+)/);
    const boardMatch = path.match(/^\/association-board\/([^/]+)/);
    const association = financeMatch?.[1] ?? boardMatch?.[1];
    const financeRoot = financeMatch && path === `/association-finance/${financeMatch[1]}`;
    const billingRoot = financeMatch && path === `/association-finance/${financeMatch[1]}/billing`;
    const boardRoute = Boolean(boardMatch);
    const alreadyProtected = Boolean(financeRoot || billingRoot || boardRoute
        || path.endsWith('/billing')
        || path.endsWith('/tax/vendor-reviews')
        || path.endsWith('/tally')
        || path.endsWith('/masters'));
    const needsProtection = Boolean(association && financeMatch && !alreadyProtected);
    const resource = path.includes('/tax') || path.includes('/billing/reminders')
        ? 'tax-configuration'
        : 'masters';

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {needsProtection ? (
                    <WorkspaceEditProtection association={association!} resource={resource} dirty={false} onDiscard={() => undefined}>
                        {children}
                    </WorkspaceEditProtection>
                ) : children}
            </AppContent>
        </AppShell>
    );
}
