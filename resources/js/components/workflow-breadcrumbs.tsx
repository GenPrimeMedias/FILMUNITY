import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';

type Crumb = { title: string; href: string };
type Props = { fallback?: ComponentProps<typeof Breadcrumbs>['breadcrumbs'] };

export function WorkflowBreadcrumbs({ fallback = [] }: Props) {
    const { url, props } = usePage();
    const [path, search] = url.split('?');
    const query = new URLSearchParams(search);
    const crumbs: Crumb[] = [];
    const add = (title: string, href: string) => crumbs.push({ title, href });

    if (path.startsWith('/membership-renewals')) {
        const owner = props.isOwner === true;
        add(owner ? 'Association dashboard' : 'My Memberships', owner ? '/association-workspaces' : '/membership-applications');
        add(owner ? 'Members' : 'Membership renewals', owner ? '/association-members' : '/membership-renewals');
        if (path.split('/').length > 2) add('Validity and renewal history', path);
    } else if (path.startsWith('/association-finance')) {
        add('Association dashboard', '/association-workspaces');
        const association = props.association as { id?: string; name?: string } | undefined;
        add('Financial Management', association?.id ? `/association-finance/${association.id}` : '/association-finance');
        if (path.endsWith('/tax')) add('GST / TDS billing', url);
        if (path.endsWith('/billing')) add(query.get('scope') === 'corrections' ? 'Credit and debit notes' : 'Invoices and settlements', url);
        if (path.endsWith('/masters')) add('Accounting masters', url);
        const section = query.get('tab');
        if (section) add(section.charAt(0).toUpperCase() + section.slice(1), url);
    } else if (path.startsWith('/filmunity-admin')) {
        add('FILMUNITY Admin', '/filmunity-admin');
        if (path === '/filmunity-admin/members') add('Registered accounts', path);
        if (path === '/filmunity-admin/payments') add('Payment audit', path);
    } else if (path.startsWith('/association-registration')) {
        add('Dashboard', '/dashboard');
        add(path.endsWith('/review') ? 'Association review' : 'Association Registration History', path.endsWith('/review') ? '/association-registration/review' : '/association-registration');
    } else if (path.startsWith('/association-workspaces')) {
        add('Association dashboard', '/association-workspaces');
        const id = path.split('/')[2];
        if (id) {
            const workspace = props.workspace as { name?: string } | undefined;
            const settings = props.settings as { display_name?: string } | undefined;
            add(settings?.display_name ?? workspace?.name ?? 'Workspace', `/association-workspaces/${id}`);
            if (path.endsWith('/setup')) add('Workspace setup', path);
        }
    } else if (path === '/association-membership-review') {
        add('Association dashboard', '/association-workspaces');
        add('Membership applications', url);
    } else if (path.startsWith('/association-payments')) {
        add('Association dashboard', '/association-workspaces');
        add('Application Payments', '/association-payments');
    } else if (path.startsWith('/membership-payments')) {
        const owner = props.canManage === true;
        const audit = props.isAuditor === true;
        add(owner ? 'Association dashboard' : audit ? 'FILMUNITY Admin' : 'My Memberships',
            owner ? '/association-workspaces' : audit ? '/filmunity-admin' : '/membership-applications');
        add(owner ? 'Application Payments' : audit ? 'Payment audit' : 'My payments',
            owner ? '/association-payments' : audit ? '/filmunity-admin/payments' : '/membership-payments');
        if (path.split('/').length > 2) add('Payment details', path);
    } else if (path === '/association-members') {
        add('Association dashboard', '/association-workspaces'); add(query.get('view') === 'renewals' ? 'Members renewal' : 'Members', url);
    } else if (path === '/my-association-workspaces' || path.startsWith('/association-member-workspaces/')) {
        add('My Memberships', '/membership-applications');
        add('My association workspaces', '/my-association-workspaces');
        if (path.startsWith('/association-member-workspaces/')) {
            const branding = props.branding as { display_name?: string } | undefined;
            add(branding?.display_name ?? 'Member workspace', path);
        }
    } else if (path === '/membership-applications/apply') {
        add('Dashboard', '/dashboard?view=member'); add('Apply for Membership', path);
    } else if (path === '/membership-applications') {
        add('Dashboard', '/dashboard?view=member'); add('My Memberships', path);
    } else if (path === '/dashboard') {
        add('Dashboard', '/dashboard?view=member');
        if (query.get('panel') === 'profile') {
            add('Professional profile', '/dashboard?panel=profile');
            if (query.get('edit') === '1') add('Edit profile', url);
        }
        if (query.get('panel') === 'applications') add('My Memberships', '/membership-applications');
    } else if (path.startsWith('/settings')) {
        add('Dashboard', '/dashboard?view=member');
        add('Account settings', '/settings/profile');
        const label = path.split('/')[2];
        if (label) add(({ profile: 'Profile', password: 'Password', appearance: 'Appearance', security: 'Security', 'two-factor': 'Two-factor authentication' } as Record<string, string>)[label] ?? label.replaceAll('-', ' '), path);
    }
    return <Breadcrumbs breadcrumbs={crumbs.length ? crumbs : fallback} />;
}
