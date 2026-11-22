import { ProtectedFields, WorkspaceEditProtection } from '@/components/workspace-edit-protection';
import AssociationEmailConfiguration from '@/components/association-email-configuration';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { control, fieldTypes, isChoice, maxMembershipFields, MembershipFields, type MembershipAnswer, type MembershipField, type WorkspaceSettings } from '@/components/association-membership-fields';
import { Button } from '@/components/ui/button';

const card = 'grid content-start gap-4 rounded-xl border bg-card p-5 text-card-foreground';
type CustomPlan = { id: string; name: string; term: 'yearly' | 'lifetime'; fee: string; active: boolean };
type NumberingSettings = WorkspaceSettings & { custom_plans?: CustomPlan[]; official_contact_email?: string; numbering?: { prefix: string; next_number: string; padding: number } };

const label = 'grid gap-2 text-sm font-medium';

export default function AssociationWorkspaceSetup({ workspaceId, settings }: {
    workspaceId: string;
    settings: NumberingSettings;
}) {
    const [saved, setSaved] = useState(false);

    return <WorkspaceEditor key={`${workspaceId}:${settings.version}`} workspaceId={workspaceId}
        settings={settings} saved={saved} onSaved={() => setSaved(true)} />;
}

function WorkspaceEditor({ workspaceId, settings, saved, onSaved }: {
    workspaceId: string;
    settings: NumberingSettings;
    saved: boolean;
    onSaved: () => void;
}) {
    const url = `/association-workspaces/${workspaceId}`;
    const [mailDirty, setMailDirty] = useState(false);
    const [mailRevision, setMailRevision] = useState(0);
    const form = useForm({
        custom_plans: settings.custom_plans ?? [] as CustomPlan[],
        official_contact_email: settings.official_contact_email ?? '',
        numbering_prefix: settings.numbering?.prefix ?? 'MEM-',
        numbering_next: settings.numbering?.next_number ?? '1',
        numbering_padding: String(settings.numbering?.padding ?? 6),
        payment_instructions: settings.payment_instructions ?? '',
        yearly_fee: settings.yearly_fee ?? '',
        lifetime_fee: settings.lifetime_fee ?? '',
        display_name: settings.display_name,
        description: settings.description ?? '',
        accent: settings.accent,
        version: settings.version,
        logo: null as File | null,
        remove_logo: false,
        fields: settings.fields,
    });
    const [editing, setEditing] = useState<string | null>(null);
    const [preview, setPreview] = useState<Record<string, MembershipAnswer>>({});
    const [previewType, setPreviewType] = useState('');
    const [previewVersion, setPreviewVersion] = useState(0);
    const changeField = (id: string, changes: Partial<MembershipField>) => {
        form.setData('fields', form.data.fields.map((field) => field.id === id ? { ...field, ...changes } : field));
        setPreview((values) => ({ ...values, [id]: '' }));
    };

    return <WorkspaceEditProtection association={workspaceId} resource="workspace" dirty={form.isDirty || mailDirty} onDiscard={() => { form.reset(); setMailDirty(false); setMailRevision(value => value + 1); }} onSaved={(path) => { if (path.endsWith('/setup')) form.setDefaults(); }}>
        <main className="grid w-full min-w-0 gap-4 p-4">
            <Head title="Workspace setup" />
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">Workspace setup</h1>
                    <p className="text-sm text-muted-foreground">{settings.display_name}</p>
                </div>
                <Button asChild variant="outline"><Link href={url}>Workspace overview</Link></Button>
            </header>
            <ProtectedFields><form className="grid gap-4" onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    fields: data.fields.map((field) => ({
                        ...field,
                        options: field.options.map((option) => option.trim()).filter(Boolean),
                    })),
                }));
                form.post(`${url}/setup`, { forceFormData: true, preserveScroll: true, onSuccess: onSaved });
            }}>
                <section className={`${card} border-t-4`} style={{ borderTopColor: form.data.accent }}>
                    <h2 className="font-semibold">Membership numbering</h2>
                    <p className="text-sm text-muted-foreground">Continue your association's existing series. These settings apply only to newly activated members. Renewals and existing member numbers stay unchanged.</p>
                    <div className="grid gap-4 md:grid-cols-3">
                        <label className={label}>Prefix (optional)<input className={control} maxLength={16} pattern="[A-Za-z0-9_-]*" placeholder="SISA-" value={form.data.numbering_prefix} onChange={(event) => form.setData('numbering_prefix', event.target.value.toUpperCase())} /><span className="font-normal text-muted-foreground">Include the separator, e.g. SISA- or TFPC-. Leave empty for numbers only.</span></label>
                        <label className={label}>Next member number<input className={control} type="number" min={1} max={999999999999} step={1} required value={form.data.numbering_next} onChange={(event) => form.setData('numbering_next', event.target.value)} /><span className="font-normal text-muted-foreground">If the last number was 6000325, enter 6000326. This value cannot move backwards.</span></label>
                        <label className={label}>Minimum digits<select className={control} value={form.data.numbering_padding} onChange={(event) => form.setData('numbering_padding', event.target.value)}>{Array.from({ length: 13 }, (_, value) => <option key={value} value={value}>{value === 0 ? 'No leading zeros' : `${value} digits`}</option>)}</select><span className="font-normal text-muted-foreground">Padding adds zeros and never truncates a longer number.</span></label>
                    </div>
                    <p className="w-fit rounded-full border bg-muted px-4 py-2 text-sm">Next number preview · <strong>{form.data.numbering_prefix}{/^\d+$/.test(form.data.numbering_next) ? form.data.numbering_next.replace(/^0+(?=\d)/, '').padStart(Number(form.data.numbering_padding), '0') : '…'}</strong></p>
                    <p className="text-sm text-muted-foreground">Before importing old records, reserve a next number above the highest legacy member number. Original legacy numbers must be preserved during a future import.</p>
                </section>
                <section className={`${card} border-t-4`} style={{ borderTopColor: form.data.accent }}>
                    <h2 className="font-semibold">Membership types and fees</h2>
                    <p className="text-sm text-muted-foreground">Set both fees in INR. Applicants choose Yearly or Life-Time when submitting their application.</p>
                    <div className="grid gap-4 md:grid-cols-2">
                        <label className={label}>Yearly membership fee (₹)
                            <input className={control} type="number" required min="0" max="9999999.99" step="0.01"
                                value={form.data.yearly_fee} onChange={(event) => form.setData('yearly_fee', event.target.value)} />
                        </label>
                        <label className={label}>Life-Time membership fee (₹)
                            <input className={control} type="number" required min="0" max="9999999.99" step="0.01"
                                value={form.data.lifetime_fee} onChange={(event) => form.setData('lifetime_fee', event.target.value)} />
                        </label>
                    </div>
                </section>
                <section className={`${card} border-t-4`} style={{ borderTopColor: form.data.accent }}>
                    <h2 className="font-semibold">Payment collection instructions</h2>
                    <label className={label}>Bank transfer or UPI instructions
                        <textarea className={control} rows={4} maxLength={3000} value={form.data.payment_instructions}
                            placeholder="Payee name, bank / UPI details, and the reference members should enter."
                            onChange={(event) => form.setData('payment_instructions', event.target.value)} />
                    </label>
                    <p className="text-sm text-muted-foreground">These instructions are copied into payment requests. You can review them before sending each request.</p>
                </section>
                <section className={`${card} border-t-4`} style={{ borderTopColor: form.data.accent }}>
                    <h2 className="font-semibold">Custom membership plans</h2>
                    <p className="text-sm text-muted-foreground">Keep the required Yearly and Life-Time plans above. Add optional plans such as Associate, Student or Honorary, with their own fee and yearly or lifetime term. Inactive plans remain in history and cannot be selected for a new application or renewal.</p>
                    {form.data.custom_plans.map((plan, index) => <fieldset key={plan.id} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4"><legend className="px-2 text-sm font-semibold">{plan.name || `Plan ${index + 1}`}</legend>
                        <label className={label}>Plan name<input className={control} required maxLength={100} value={plan.name} onChange={(event) => form.setData('custom_plans', form.data.custom_plans.map((item) => item.id === plan.id ? { ...item, name: event.target.value } : item))} /></label>
                        <label className={label}>Term<select className={control} value={plan.term} onChange={(event) => form.setData('custom_plans', form.data.custom_plans.map((item) => item.id === plan.id ? { ...item, term: event.target.value as CustomPlan['term'] } : item))}><option value="yearly">Yearly (12 months)</option><option value="lifetime">Life-Time</option></select></label>
                        <label className={label}>Fee (INR)<input className={control} type="number" min={0} max={9999999.99} step="0.01" required value={plan.fee} onChange={(event) => form.setData('custom_plans', form.data.custom_plans.map((item) => item.id === plan.id ? { ...item, fee: event.target.value } : item))} /></label>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={plan.active} onChange={(event) => form.setData('custom_plans', form.data.custom_plans.map((item) => item.id === plan.id ? { ...item, active: event.target.checked } : item))} />Available to members</label>
                    </fieldset>)}
                    <Button type="button" variant="outline" className="w-fit" disabled={form.data.custom_plans.length >= 20} onClick={() => form.setData('custom_plans', [...form.data.custom_plans, { id: crypto.randomUUID(), name: '', term: 'yearly', fee: '0.00', active: true }])}>Add membership plan</Button>
                </section>
                <div className="grid gap-4">
                    <section className={`${card} border-t-4`} style={{ borderTopColor: form.data.accent }}>
                        <h2 className="font-semibold">Workspace branding</h2>
                    <label className={label}>Official contact email<input type="email" required maxLength={254} className={control} value={form.data.official_contact_email} onChange={(event) => form.setData('official_contact_email', event.target.value)} /><span className="font-normal text-muted-foreground">Used for association notices and Reply-To. Email is delivered through FILMUNITY's authenticated sender.</span></label>
                        <div className="grid gap-4 md:grid-cols-2">
                            <label className={label}>Display name
                                <input className={control} required maxLength={150} value={form.data.display_name} onChange={(event) => form.setData('display_name', event.target.value)} />
                            </label>
                            <label className={label}>Description (optional)
                                <textarea className={control} rows={4} maxLength={1000} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                            </label>
                            <label className={label}>Brand accent
                                <input className={`${control} h-10`} type="color" value={form.data.accent} onChange={(event) => form.setData('accent', event.target.value)} />
                            </label>
                            <label className={label}>Logo (optional)
                                <input className={control} type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => {
                                    form.setData({ ...form.data, logo: event.target.files?.[0] ?? null, remove_logo: false });
                                }} />
                                <span className="font-normal text-muted-foreground">Square PNG, JPEG or WebP, 512–2048 px per side, up to 1 MB. Recommended: 1024 × 1024 px, closely cropped.</span>
                            </label>
                        </div>
                        {settings.logo_url && <div className="flex items-center gap-3">
                            {!form.data.remove_logo && <img className="size-12 rounded-lg object-contain" src={settings.logo_url} alt="Current association logo" />}
                            <Button type="button" variant="outline" onClick={() => form.setData('remove_logo', !form.data.remove_logo)}>
                                {form.data.remove_logo ? 'Keep current logo' : 'Remove current logo'}
                            </Button>
                        </div>}
                    </section>
                    <section className={`${card} border-t-4`} style={{ borderTopColor: form.data.accent }}>
                        <div>
                            <h2 className="font-semibold">Membership form fields</h2>
                            <p className="text-sm text-muted-foreground">{form.data.fields.length} / {maxMembershipFields} custom fields. Full name and email are included.</p>
                        </div>
                        <div className="grid gap-3">
                            {form.data.fields.map((field, index) => (
                                <div key={field.id} className="grid min-w-0 gap-3 rounded-lg border bg-background p-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="min-w-0 break-words text-sm">
                                            <p className="font-semibold">{field.label || `Field ${index + 1}`}</p>
                                            <p className="text-muted-foreground">{fieldTypes[field.type]} · {field.required ? 'Required' : 'Optional'}</p>
                                        </div>
                                        <Button type="button" size="sm" variant="ghost" aria-expanded={editing === field.id} onClick={() => setEditing(editing === field.id ? null : field.id)}>
                                            {editing === field.id ? 'Hide' : 'Edit'}
                                        </Button>
                                    </div>
                                    {editing === field.id && <>
                                        <label className={label}>Field label
                                            <input className={control} required maxLength={100} value={field.label} onChange={(event) => changeField(field.id, { label: event.target.value })} />
                                        </label>
                                        <label className={label}>Field type
                                            <select className={control} value={field.type} onChange={(event) => changeField(field.id, {
                                                type: event.target.value as MembershipField['type'],
                                                options: [],
                                                description: event.target.value === 'checkbox'
                                                    ? field.description || 'I (member / association) agree with the Terms & Conditions and Privacy Policy.'
                                                    : field.description ?? '',
                                            })}>
                                                {Object.entries(fieldTypes).map(([value, name]) => <option key={value} value={value}>{name}</option>)}
                                            </select>
                                        </label>
                                        {field.type === 'checkbox' && <label className={label}>Acknowledgement description
                                            <textarea className={control} rows={3} maxLength={1000}
                                                value={field.description ?? ''}
                                                placeholder="I (member / association) agree with the Terms & Conditions and Privacy Policy."
                                                onChange={(event) => changeField(field.id, { description: event.target.value })} />
                                        </label>}
                                        {isChoice(field.type) && <label className={label}>Options (one per line)
                                            <textarea className={control} rows={4} required value={field.options.join('\n')}
                                                onChange={(event) => changeField(field.id, { options: event.target.value.split('\n') })} />
                                        </label>}
                                        <label className="flex items-center gap-2 text-sm">
                                            <input type="checkbox" checked={field.required} onChange={(event) => changeField(field.id, { required: event.target.checked })} />Required
                                        </label>
                                        <Button type="button" variant="outline" className="w-fit" onClick={() => {
                                            form.setData('fields', form.data.fields.filter((item) => item.id !== field.id));
                                            setEditing(null);
                                        }}>Remove field</Button>
                                    </>}
                                </div>
                            ))}
                        </div>
                        <Button type="button" variant="outline" className="w-fit" disabled={form.data.fields.length >= maxMembershipFields} onClick={() => {
                            const id = crypto.randomUUID();
                            form.setData('fields', [...form.data.fields, { id, label: '', type: 'text', required: false, options: [] }]);
                            setEditing(id);
                        }}>Add a field</Button>
                    </section>
                </div>
                {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-destructive">{error}</p>)}
                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={form.processing}>Save workspace settings</Button>
                    {saved && !form.isDirty && <p role="status" className="text-sm text-muted-foreground">Workspace settings saved.</p>}
                </div>
            </form></ProtectedFields>
            <AssociationEmailConfiguration key={mailRevision} association={workspaceId} onDirty={setMailDirty} />
            <section className={`${card} border-t-4`} style={{ borderTopColor: form.data.accent }}>
                <div>
                    <h2 className="font-semibold">Membership form preview</h2>
                    <p className="text-sm text-muted-foreground">Test answers stay in this preview.</p>
                </div>
                <label className={label}>Membership type (required)
                    <select className={control} value={previewType} onChange={(event) => setPreviewType(event.target.value)}>
                        <option value="">Choose a membership type</option>
                        <option value="yearly">Yearly · ₹{form.data.yearly_fee || '0.00'}</option>
                        <option value="lifetime">Life-Time · ₹{form.data.lifetime_fee || '0.00'}</option>
                    </select>
                </label>
                <div className="grid gap-4 md:grid-cols-2">
                    <label className={label}>Full name (required)<input className={control} value={String(preview.name ?? '')} onChange={(event) => setPreview({ ...preview, name: event.target.value })} /></label>
                    <label className={label}>Email address (required)<input type="email" className={control} value={String(preview.email ?? '')} onChange={(event) => setPreview({ ...preview, email: event.target.value })} /></label>
                </div>
                {form.data.fields.length ? <MembershipFields key={previewVersion} preview prefix="preview" fields={form.data.fields} answers={preview} onChange={(id, value) => setPreview({ ...preview, [id]: value })} />
                    : <label className={label}>Tell the association about your work and why you want to join
                        <textarea className={control} rows={4} value={String(preview.statement ?? '')} onChange={(event) => setPreview({ ...preview, statement: event.target.value })} />
                    </label>}
                <Button type="button" variant="outline" className="w-fit" onClick={() => { setPreview({}); setPreviewType(''); setPreviewVersion((version) => version + 1); }}>Clear preview answers</Button>
            </section>
        </main>
    </WorkspaceEditProtection>;
}

AssociationWorkspaceSetup.layout = { breadcrumbs: [{ title: 'Association dashboard', href: '/association-workspaces' }] };
