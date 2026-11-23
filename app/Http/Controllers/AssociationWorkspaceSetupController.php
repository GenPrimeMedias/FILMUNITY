<?php

namespace App\Http\Controllers;

use App\Support\AssociationMembershipForm;
use App\Support\AssociationMembershipNumber;
use App\Support\AssociationPlans;
use App\Support\AssociationWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AssociationWorkspaceSetupController extends Controller
{
    public function edit(Request $request, string $workspace): Response
    {
        $association = AssociationWorkspace::forAdmin($request->user())->where('id', $workspace)->first(['id', 'name', 'official_contact_email']);
        abort_if($association === null, 404);

        return Inertia::render('association-workspace-setup', [
            'workspaceId' => $workspace,
            'settings' => [
                ...AssociationMembershipForm::present($association, AssociationMembershipForm::settings($workspace)),
                'official_contact_email' => $association->official_contact_email,
                'custom_plans' => AssociationPlans::custom($workspace),
                'numbering' => AssociationMembershipNumber::settings($workspace),
                'payment_instructions' => DB::table('association_workspace_settings')->where('association_id', $workspace)->value('payment_instructions'),
            ],
        ]);
    }

    public function update(Request $request, string $workspace): RedirectResponse
    {
        $owned = AssociationWorkspace::forAdmin($request->user())->where('id', $workspace);
        abort_unless((clone $owned)->exists(), 404);

        $data = $request->validate([
            'custom_plans' => ['sometimes', 'array', 'list', 'max:20'],
            'custom_plans.*' => ['required', 'array:id,name,term,fee,active'],
            'custom_plans.*.id' => ['required', 'uuid', 'distinct'],
            'custom_plans.*.name' => ['required', 'string', 'max:100'],
            'custom_plans.*.term' => ['required', Rule::in(['yearly', 'lifetime'])],
            'custom_plans.*.fee' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'],
            'custom_plans.*.active' => ['required', 'boolean'],
            'official_contact_email' => ['sometimes', 'required', 'email:rfc', 'max:254'],
            'numbering_prefix' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]*$/D'],
            'numbering_next' => ['sometimes', 'required', 'integer', 'min:1', 'max:999999999999'],
            'numbering_padding' => ['required_with:numbering_next', 'integer', 'min:0', 'max:12'],
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'payment_instructions' => ['nullable', 'string', 'max:3000'],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:min_width=512,min_height=512,max_width=2048,max_height=2048,ratio=1/1'],
            'remove_logo' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
            'yearly_fee' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'],
            'lifetime_fee' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'],
            'fields' => ['sometimes', 'array', 'list', 'max:'.AssociationMembershipForm::MAX_FIELDS],
            'fields.*' => ['required', 'array:id,label,description,type,required,options'],
            'fields.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'fields.*.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'fields.*.label' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'fields.*.type' => ['required', Rule::in(AssociationMembershipForm::TYPES)],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.options' => ['sometimes', 'array', 'list', 'max:20'],
            'fields.*.options.*' => ['required', 'string', 'max:100'],
        ]);

        $fields = $data['fields'] ?? [];
        foreach ($fields as $index => &$field) {
            $choice = in_array($field['type'], ['select', 'radio', 'multiple'], true);
            $options = $choice ? ($field['options'] ?? []) : [];
            if ($choice && (count($options) < 2 || count(array_unique($options)) !== count($options))) {
                throw ValidationException::withMessages(["fields.$index.options" => 'Enter at least two distinct choices.']);
            }
            $field['description'] = $field['type'] === 'checkbox'
                ? trim($field['description'] ?? '') : null;
            $field['options'] = $options;
            $field['required'] = (bool) $field['required'];
        }
        unset($field);

        $upload = $request->file('logo');
        $logo = $upload ? $upload->getMimeType().';'.base64_encode($upload->get()) : null;

        DB::transaction(function () use ($owned, $workspace, $data, $fields, $logo, $request): void {
            abort_if((clone $owned)->lockForUpdate()->first(['id']) === null, 404);
            $current = AssociationMembershipForm::settings($workspace);
            if ((int) ($current?->version ?? 0) !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'The workspace settings changed. Reload this page before saving.']);
            }

            AssociationMembershipNumber::configure($workspace, $request->user(), $data);

            if (isset($data['official_contact_email'])) {
                DB::table('association_registration_applications')->where('id', $workspace)->update(['official_contact_email' => $data['official_contact_email'], 'updated_at' => now()->toIso8601String()]);
            }

            $values = [
                'yearly_fee_minor' => AssociationMembershipForm::feeMinor((string) $data['yearly_fee']),
                'lifetime_fee_minor' => AssociationMembershipForm::feeMinor((string) $data['lifetime_fee']),
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
                'payment_instructions' => $data['payment_instructions'] ?? null,
                'accent' => $data['accent'],
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                'version' => (int) $data['version'] + 1,
            ];
            if ($logo !== null || $data['remove_logo']) {
                $values['logo'] = $data['remove_logo'] ? null : $logo;
            }

            DB::table('association_workspace_settings')->updateOrInsert(['association_id' => $workspace], $values);
            try {
                AssociationPlans::save($workspace, $data);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['custom_plans' => 'Plan names must be unique, including archived plans.']);
            }
        });

        return to_route('association-workspace-setup.edit', $workspace)->with('status', 'Workspace settings saved.');
    }

    public function logo(string $association): \Illuminate\Http\Response
    {
        $logo = DB::table('association_workspace_settings as settings')
            ->join('association_registration_applications as registrations', 'registrations.id', '=', 'settings.association_id')
            ->where('settings.association_id', $association)->where('registrations.status', 'approved')->value('settings.logo');
        abort_if($logo === null, 404);
        [$mime, $encoded] = explode(';', $logo, 2);

        return response(base64_decode($encoded), 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
