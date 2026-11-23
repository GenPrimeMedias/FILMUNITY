import { SearchableSelect } from '@/components/searchable-select';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { control, MembershipFields, type MembershipAnswer, type WorkspaceSettings } from '@/components/association-membership-fields';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

const membershipUrl = '/membership-applications';

export default function MembershipApply({ associations, identity }: {
    associations: (WorkspaceSettings & { id: string; industry: string; city: string })[];
    identity: { name: string; email: string };
}) {
    const { filmunityNavigation } = usePage<{
        filmunityNavigation?: { canApplyForAssociation: boolean; associationSignup: boolean };
    }>().props;
    const form = useForm({ association_id: '', statement: '', version: 0, answers: {} as Record<string, MembershipAnswer> });
    const selected = associations.find((association) => association.id === form.data.association_id);

    return (
        <main className="grid w-full min-w-0 gap-5 p-4">
            <Head title="Apply for Membership" />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">Apply for Membership</h1>
                {filmunityNavigation?.associationSignup && <Button variant="outline" asChild>
                    <Link href="/association-registration">
                        {filmunityNavigation?.canApplyForAssociation ? 'Register an association' : 'Association registration history'}
                    </Link>
                </Button>}
                <Button variant="outline" asChild><Link href="/membership-applications">My Memberships</Link></Button>
            </div>
            <section aria-labelledby="membership-apply-title" className="grid gap-4 rounded-xl border bg-card p-5 text-card-foreground">
                <h2 id="membership-apply-title" className="font-semibold">Apply for membership</h2>
                {form.errors.association_id && <p role="alert" className="text-sm text-destructive">{form.errors.association_id}</p>}
                {associations.length ? (
                    <form className="grid gap-4" onSubmit={(event) => {
                        event.preventDefault();
                        form.post(membershipUrl, { onSuccess: () => form.reset() });
                    }}>
                        <div className="grid gap-2">
                            <Label htmlFor="membership-association">Association</Label>
                            <SearchableSelect id="membership-association" required className={control} disabled={form.processing} value={form.data.association_id} placeholder="Select an approved association" options={associations.map(({ id, display_name, industry, city }) => ({ value: id, label: `${display_name} · ${industry} · ${city}` }))} onValueChange={(id) => {
                                const association = associations.find((item) => item.id === id);
                                form.clearErrors();
                                form.setData({ association_id: association?.id ?? '', version: association?.version ?? 0, statement: '', answers: {} });
                            }} />
                        </div>
                        {selected && (
                            <div className="grid gap-4 rounded-lg border border-t-4 bg-background p-4" style={{ borderTopColor: selected.accent }}>
                                <div className="flex items-start gap-3">
                                    {selected.logo_url && <img className="size-12 rounded-lg object-contain" src={selected.logo_url} alt="" />}
                                    <div className="min-w-0">
                                        <h3 className="break-words font-semibold">{selected.display_name}</h3>
                                        {selected.description && <p className="whitespace-pre-wrap break-words text-sm text-muted-foreground">{selected.description}</p>}
                                    </div>
                                </div>
                                <section className="rounded-lg border p-3 text-sm text-muted-foreground">After association approval, you will choose Yearly or Life-Time membership and confirm the current fee. Current reference fees: Yearly ₹{selected.yearly_fee ?? 'not configured'} · Life-Time ₹{selected.lifetime_fee ?? 'not configured'}.</section>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <label className="grid gap-2 text-sm font-medium">Full name<input className={control} value={identity.name} readOnly /></label>
                                    <label className="grid gap-2 text-sm font-medium">Email address<input className={control} value={identity.email} readOnly /></label>
                                </div>
                                {selected.fields.length ? (
                                    <MembershipFields key={selected.id} fields={selected.fields} answers={form.data.answers} prefix="application"
                                        errors={form.errors as Record<string, string>}
                                        onChange={(id, value) => form.setData('answers', { ...form.data.answers, [id]: value })} />
                                ) : (
                                    <div className="grid gap-2">
                                        <Label htmlFor="membership-statement">Tell the association about your work and why you want to join</Label>
                                        <textarea id="membership-statement" required rows={4} maxLength={2000} className={control} value={form.data.statement} onChange={(event) => form.setData('statement', event.target.value)} />
                                        {form.errors.statement && <p role="alert" className="text-sm text-destructive">{form.errors.statement}</p>}
                                    </div>
                                )}
                                {Object.entries(form.errors).filter(([key]) => !['association_id', 'membership_type', 'statement'].includes(key) && !key.startsWith('answers.')).map(([key, error]) => <p key={key} role="alert" className="text-sm text-destructive">{error}</p>)}
                                <Button type="submit" className="w-fit" disabled={form.processing || selected.yearly_fee === null || selected.lifetime_fee === null}>Submit application</Button>
                            </div>
                        )}
                    </form>
                ) : <p className="text-sm text-muted-foreground">No approved associations are available for a new application.</p>}
            </section>

        </main>
    );
}

MembershipApply.layout = {
    breadcrumbs: [{ title: 'Apply for Membership', href: '/membership-applications/apply' }],
};
