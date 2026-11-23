import { SearchableSelect } from '@/components/searchable-select';
import type { ChangeEvent } from 'react';

export const fieldTypes = {
    text: 'Short text',
    textarea: 'Long text',
    number: 'Number',
    email: 'Email',
    tel: 'Phone',
    url: 'Website URL',
    date: 'Date',
    time: 'Time',
    select: 'Dropdown',
    radio: 'Single choice',
    multiple: 'Multiple choices',
    checkbox: 'Checkbox / acknowledgement',
    file: 'Document upload',
};

export type MembershipField = {
    id: string;
    label: string;
    description?: string | null;
    type: keyof typeof fieldTypes;
    required: boolean;
    options: string[];
};

export type WorkspaceSettings = {
    payment_instructions?: string | null;
    yearly_fee: string | null;
    lifetime_fee: string | null;
    currency: 'INR';
    display_name: string;
    description: string | null;
    accent: string;
    logo_url: string | null;
    version: number;
    fields: MembershipField[];
};

export const maxMembershipFields = 25;
export type MembershipAnswer = string | string[] | boolean | File | null;
export const isChoice = (type: MembershipField['type']) => ['select', 'radio', 'multiple'].includes(type);

export const control = 'w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring';

export function MembershipFields({ fields, answers, prefix, errors = {}, onChange, preview = false }: {
    fields: MembershipField[];
    answers: Record<string, MembershipAnswer>;
    prefix: string;
    errors?: Record<string, string>;
    onChange: (id: string, value: MembershipAnswer) => void;
    preview?: boolean;
}) {
    return <div className="grid gap-4 md:grid-cols-2">
        {fields.map((field, index) => {
            const id = `${prefix}-${field.id}`;
            const label = `${field.label || `Field ${index + 1}`}${field.required ? ' (required)' : ''}`;
            const error = errors[`answers.${field.id}`] ?? Object.entries(errors).find(([key]) => key.startsWith(`answers.${field.id}.`))?.[1];
            const answer = answers[field.id];
            const selected = Array.isArray(answer) ? answer : [];
            const input = {
                id,
                className: control,
                value: typeof answer === 'string' ? answer : '',
                required: !preview && field.required,
                'aria-invalid': Boolean(error),
                'aria-describedby': error ? `${id}-error` : undefined,
                onChange: (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
                    onChange(field.id, event.target.value),
            };

            return <div key={field.id} className="grid content-start gap-2">
                {field.type === 'radio' || field.type === 'multiple' ? (
                    <fieldset className="grid gap-2" aria-describedby={input['aria-describedby']}>
                        <legend className="mb-2 text-sm font-medium">{label}</legend>
                        {field.options.filter(Boolean).map((option, optionIndex) => (
                            <label key={optionIndex} className="flex items-center gap-2 text-sm">
                                <input type={field.type === 'radio' ? 'radio' : 'checkbox'} name={id} value={option}
                                    checked={field.type === 'radio' ? input.value === option : selected.includes(option)}
                                    required={field.type === 'radio' && input.required}
                                    onChange={(event) => onChange(field.id, field.type === 'radio' ? option :
                                        event.target.checked ? [...selected, option] : selected.filter((value) => value !== option))} />
                                {option}
                            </label>
                        ))}
                    </fieldset>
                ) : <>
                    {field.type === 'checkbox'
                        ? <p className="text-sm font-medium">{label}</p>
                        : <label htmlFor={id} className="text-sm font-medium">{label}</label>}
                    {field.type === 'textarea' && <textarea {...input} maxLength={2000} rows={4} />}
                    {field.type === 'select' && <SearchableSelect id={id} className={control} value={input.value} required={input.required} aria-invalid={input['aria-invalid']} aria-describedby={input['aria-describedby']} options={[...new Set(field.options.filter(Boolean))].map((option) => ({ value: option, label: option }))} onValueChange={(value) => onChange(field.id, value)} />}
                    {field.type === 'checkbox' && <label className="flex items-start gap-2 text-sm leading-relaxed">
                        <input id={id} type="checkbox" className="mt-1 size-4 shrink-0"
                            checked={answer === true} required={input.required} aria-invalid={Boolean(error)}
                            aria-describedby={input['aria-describedby']}
                            onChange={(event) => onChange(field.id, event.target.checked)} />
                        <span className="whitespace-pre-wrap break-words">{field.description?.trim() || field.label || 'I agree'}</span>
                    </label>}
                    {field.type === 'file' && <>
                        <input id={id} type="file" className={control} accept=".pdf,.jpg,.jpeg,.png"
                            required={input.required} aria-describedby={input['aria-describedby']}
                            onChange={(event) => onChange(field.id, event.target.files?.[0] ?? null)} />
                        <p className="text-xs text-muted-foreground">PDF, JPEG or PNG up to 5 MB. Up to 10 MB total per application.</p>
                    </>}
                    {!['textarea', 'select', 'checkbox', 'file'].includes(field.type) &&
                        <input {...input} type={field.type}
                            step={field.type === 'number' ? 'any' : undefined}
                            maxLength={field.type === 'url' ? 2048 : field.type === 'tel' ? 30 : 254} />}
                </>}
                {error && <p id={`${id}-error`} role="alert" className="text-sm text-destructive">{error}</p>}
            </div>;
        })}
    </div>;
}
