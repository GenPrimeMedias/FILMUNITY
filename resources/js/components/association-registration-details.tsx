import { Button } from '@/components/ui/button';

const registrationUrl = '/association-registration';
const statusLabels = { pending: 'Pending review', approved: 'Approved', declined: 'Declined' };

export type AssociationRegistrationRecord = {
    id: string;
    name: string;
    organisation_type: string;
    industry: string;
    city: string;
    representative_role: string;
    status: keyof typeof statusLabels;
    review_note: string | null;
    registration_number: string | null;
    registering_authority: string | null;
    state_or_region: string | null;
    registered_on: string | null;
    has_certificate: boolean;
    official_contact_email: string | null;
    registered_office_address: string | null;
    website: string | null;
    created_at: string;
    reviewed_at: string | null;
    approval_email_sent_at: string | null;
};


export function AssociationRegistrationDetails({ application }: { application: AssociationRegistrationRecord }) {
    const values = [
        ['Status', statusLabels[application.status]],
        ['Organisation type', application.organisation_type],
        ['Industry', application.industry],
        ['City / region', [application.city, application.state_or_region].filter(Boolean).join(' · ')],
        ['Representative role', application.representative_role],
        ['Registration number', application.registration_number],
        ['Registering authority', application.registering_authority],
        ['Date registered', application.registered_on],
        ['Official contact email', application.official_contact_email],
        ['Registered office address', application.registered_office_address],
        ['Website', application.website],
        ['Application reference', application.id],
        ['Submitted', application.created_at],
        ['Decision recorded', application.reviewed_at],
        ['Note to applicant', application.review_note],
        ['Approval email sent', application.approval_email_sent_at],
    ];

    return (
        <div className="grid gap-4">
            <Button variant="outline" className="w-fit" asChild><a href={`${registrationUrl}/${application.id}/print`} target="_blank" rel="noreferrer">Print application / Save as PDF</a></Button>
            <fieldset className="rounded-xl border border-t-4 border-t-primary p-4"><legend className="px-2 text-sm font-semibold">Association record</legend><dl className="grid gap-3 sm:grid-cols-2">
                {values.filter(([, value]) => Boolean(value)).map(([label, value]) => (
                    <div key={label} className="min-w-0 rounded-lg border p-3">
                        <dt className="text-xs text-muted-foreground">{label}</dt>
                        <dd className="whitespace-pre-wrap break-words text-sm">{value}</dd>
                    </div>
                ))}
            </dl></fieldset>
            {application.has_certificate ? (
                <Button variant="outline" className="w-fit" asChild>
                    <a href={`${registrationUrl}/${application.id}/certificate`}>Download registration certificate</a>
                </Button>
            ) : <p className="text-sm text-muted-foreground">No registration certificate on file.</p>}
        </div>
    );
}

