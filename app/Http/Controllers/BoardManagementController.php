<?php

namespace App\Http\Controllers;

use App\Support\BoardGovernance;
use App\Support\BoardNotifications;
use App\Support\WorkspaceProtection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class BoardManagementController
{
    public function index(Request $request, string $association): Response
    {
        $this->owner($request, $association);
        $data = $this->data($association);

        return Inertia::render('board-management', [
            'associationId' => $association,
            'section' => 'overview',
            ...$data,
        ]);
    }

    public function setup(Request $request, string $association): Response
    {
        $this->owner($request, $association);

        return Inertia::render('board-management', [
            'associationId' => $association,
            'section' => 'setup',
            ...$this->data($association),
        ]);
    }

    public function terms(Request $request, string $association): Response
    {
        $this->owner($request, $association);

        return Inertia::render('board-management', [
            'associationId' => $association,
            'section' => $request->query('view') === 'members' ? 'members' : 'terms',
            'selectedTermId' => $request->query('term'),
            ...$this->data($association),
        ]);
    }

    public function meetings(Request $request, string $association): Response
    {
        $this->owner($request, $association);

        return Inertia::render('board-management', [
            'associationId' => $association,
            'section' => 'meetings',
            ...$this->data($association),
        ]);
    }

    public function minutes(Request $request, string $association): Response
    {
        $this->owner($request, $association);

        return Inertia::render('board-management', [
            'associationId' => $association,
            'section' => 'minutes',
            'selectedMeetingId' => $request->query('meeting'),
            ...$this->data($association),
        ]);
    }

    public function actions(Request $request, string $association): Response
    {
        $this->owner($request, $association);

        return Inertia::render('board-management', [
            'associationId' => $association,
            'section' => 'actions',
            'selectedMeetingId' => $request->query('meeting'),
            'selectedAgendaItemId' => $request->query('agenda_item'),
            ...$this->data($association),
        ]);
    }

    public function directory(Request $request, string $association): Response
    {
        $this->owner($request, $association);

        return Inertia::render('board-management', ['associationId' => $association, 'section' => 'directory', ...$this->data($association)]);
    }

    public function portrait(Request $request, string $association, string $appointment): RedirectResponse
    {
        $this->owner($request, $association);
        $request->validate(['portrait' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $record = DB::table('association_governance_appointments')->where('association_id', $association)->where('id', $appointment)->firstOrFail();
        $file = $request->file('portrait');
        $path = $file->storeAs('association-board/'.$association.'/portraits', $appointment.'.'.$file->extension(), 'local');
        if ($record->portrait_path && Storage::disk('local')->exists($record->portrait_path)) {
            Storage::disk('local')->delete($record->portrait_path);
        }
        DB::table('association_governance_appointments')->where('id', $appointment)->update(['portrait_path' => $path, 'updated_at' => now()]);

        return back()->with('success', 'Board portrait updated.');
    }

    public function portraitFile(Request $request, string $association, string $appointment): \Illuminate\Http\Response
    {
        $this->owner($request, $association);
        $path = DB::table('association_governance_appointments')->where('association_id', $association)->where('id', $appointment)->value('portrait_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => Storage::disk('local')->mimeType($path) ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function memberAvatar(Request $request, string $association, int $member): \Illuminate\Http\Response
    {
        $this->owner($request, $association);
        abort_unless(DB::table('association_memberships')->where('association_id', $association)->where('member_id', $member)->exists(), 404);
        $photo = DB::table('professional_profile_drafts')->where('user_id', $member)->value("profile->>'photo'");
        abort_unless(is_string($photo) && preg_match('~^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$~D', $photo, $parts), 404);
        $bytes = base64_decode($parts[2], true);
        abort_if($bytes === false, 404);

        return response($bytes, 200, ['Content-Type' => $parts[1], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function publicDirectory(string $association): \Illuminate\Http\Response
    {
        $settings = DB::table('association_governance_settings')->where('association_id', $association)->first();
        abort_unless($settings?->public_directory_enabled && $settings->directory_visibility === 'public', 404);
        $associationRecord = DB::table('association_registration_applications')->where('id', $association)->first(['name']);
        abort_if(! $associationRecord, 404);
        $appointments = DB::table('association_governance_appointments as appointment')->join('association_governance_positions as position', 'position.id', '=', 'appointment.position_id')->leftJoin('users as member', 'member.id', '=', 'appointment.member_id')->where('appointment.association_id', $association)->where('appointment.status', 'serving')->select('position.title as position_title', 'member.name as member_name', 'appointment.external_name')->orderBy('position.display_order')->get();

        return response()->view('board.public-directory', ['association' => $associationRecord, 'appointments' => $appointments])->header('Cache-Control', 'public, max-age=300')->header('X-Content-Type-Options', 'nosniff');
    }

    public function saveSetup(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate([
            'body_name' => ['required', 'string', 'max:160'],
            'body_type' => ['required', 'string', 'max:40'],
            'positions' => ['nullable', 'array', 'max:50'],
            'positions.*.title' => ['required', 'string', 'max:160'],
            'positions.*.seats' => ['required', 'integer', 'min:1', 'max:999'],
            'positions.*.responsibilities' => ['nullable', 'string', 'max:5000'],
            'positions.*.display_order' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'positions.*.office_bearer' => ['boolean'],
            'positions.*.voting_eligible' => ['boolean'],
            'policy' => ['required', 'array'],
            'policy.effective_from' => ['required', 'date'],
            'policy.timezone' => ['required', 'timezone'],
            'policy.quorum_type' => [Rule::in(['fixed_count', 'percentage'])],
            'policy.quorum_count' => ['nullable', 'integer', 'min:1'],
            'policy.quorum_percent' => ['nullable', 'numeric', 'gt:0', 'lte:100'],
            'policy.quorum_denominator' => [Rule::in(['eligible_seats', 'serving_appointments', 'attending_invited'])],
            'policy.rounding_policy' => [Rule::in(['ceil', 'floor', 'round'])],
            'policy.voting_rule' => [Rule::in(['ordinary_majority', 'percentage', 'unanimity'])],
            'policy.majority_percent' => ['nullable', 'numeric', 'gt:50', 'lte:100'],
            'policy.notice_days' => ['required', 'integer', 'min:0', 'max:365'],
            'policy.allow_manual_determination' => ['boolean'],
            'policy.allow_external_appointees' => ['boolean'],
            'policy.policy_reference' => ['nullable', 'string', 'max:240'],
            'policy.reviewed' => ['boolean'],
        ]);
        if (($fields['policy']['quorum_type'] ?? 'fixed_count') === 'fixed_count' && empty($fields['policy']['quorum_count'])) {
            return back()->withErrors(['policy.quorum_count' => 'Fixed-count quorum requires a count.']);
        }
        if (($fields['policy']['quorum_type'] ?? '') === 'percentage' && empty($fields['policy']['quorum_percent'])) {
            return back()->withErrors(['policy.quorum_percent' => 'Percentage quorum requires a percentage.']);
        }
        if (($fields['policy']['voting_rule'] ?? '') === 'percentage' && empty($fields['policy']['majority_percent'])) {
            return back()->withErrors(['policy.majority_percent' => 'Percentage voting requires a threshold.']);
        }

        DB::transaction(function () use ($request, $association, $fields): void {
            $body = DB::table('association_governance_bodies')->where('association_id', $association)->where('name', $fields['body_name'])->first();
            if ($body === null) {
                $bodyId = (string) Str::uuid();
                DB::table('association_governance_bodies')->insert(['id' => $bodyId, 'association_id' => $association, 'name' => $fields['body_name'], 'body_type' => $fields['body_type'], 'created_at' => now(), 'updated_at' => now()]);
                $body = (object) ['id' => $bodyId];
            } else {
                DB::table('association_governance_bodies')->where('id', $body->id)->update(['body_type' => $fields['body_type'], 'updated_at' => now()]);
            }
            foreach ($fields['positions'] ?? [] as $order => $position) {
                $existing = DB::table('association_governance_positions')->where('association_id', $association)->where('body_id', $body->id)->where('title', $position['title'])->first();
                $values = ['seats' => $position['seats'], 'display_order' => $position['display_order'] ?? ($order + 1), 'responsibilities' => $position['responsibilities'] ?? null, 'office_bearer' => (bool) ($position['office_bearer'] ?? false), 'voting_eligible' => (bool) ($position['voting_eligible'] ?? true), 'active' => true, 'updated_at' => now()];
                if ($existing) {
                    DB::table('association_governance_positions')->where('id', $existing->id)->update($values);
                } else {
                    DB::table('association_governance_positions')->insert([...$values, 'id' => (string) Str::uuid(), 'association_id' => $association, 'body_id' => $body->id, 'title' => $position['title'], 'created_at' => now()]);
                }
            }
            $latest = DB::table('association_governance_policies')->where('association_id', $association)->where('body_id', $body->id)->max('version') ?? 0;
            $policy = $fields['policy'];
            DB::table('association_governance_policies')->insert([
                'id' => (string) Str::uuid(), 'association_id' => $association, 'body_id' => $body->id, 'version' => $latest + 1,
                'status' => ($policy['reviewed'] ?? false) ? 'reviewed' : 'draft', 'effective_from' => $policy['effective_from'], 'timezone' => $policy['timezone'],
                'quorum_type' => $policy['quorum_type'], 'quorum_count' => $policy['quorum_count'] ?? null, 'quorum_percent' => $policy['quorum_percent'] ?? null,
                'quorum_denominator' => $policy['quorum_denominator'], 'rounding_policy' => $policy['rounding_policy'], 'voting_rule' => $policy['voting_rule'],
                'majority_percent' => $policy['majority_percent'] ?? null, 'notice_days' => $policy['notice_days'], 'allow_manual_determination' => (bool) ($policy['allow_manual_determination'] ?? false), 'allow_external_appointees' => (bool) ($policy['allow_external_appointees'] ?? false),
                'policy_reference' => $policy['policy_reference'] ?? null, 'reviewed_on' => ($policy['reviewed'] ?? false) ? now()->toDateString() : null,
                'reviewed_by' => ($policy['reviewed'] ?? false) ? $request->user()->getAuthIdentifier() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('association_governance_events')->insert(['association_id' => $association, 'actor_id' => $request->user()->getAuthIdentifier(), 'entity_type' => 'policy', 'entity_id' => $body->id, 'action' => 'setup_saved', 'snapshot' => json_encode($fields, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });

        return back()->with('success', 'Governance setup saved as a new policy version.');
    }

    public function savePositions(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate([
            'body_name' => ['required', 'string', 'max:160'],
            'body_type' => ['required', 'string', 'max:40'],
            'positions' => ['nullable', 'array', 'max:50'],
            'positions.*.title' => ['required', 'string', 'max:160'],
            'positions.*.seats' => ['required', 'integer', 'min:1', 'max:999'],
            'positions.*.responsibilities' => ['nullable', 'string', 'max:5000'],
            'positions.*.display_order' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'positions.*.office_bearer' => ['boolean'],
            'positions.*.voting_eligible' => ['boolean'],
        ]);
        DB::transaction(function () use ($request, $association, $fields): void {
            $body = DB::table('association_governance_bodies')->where('association_id', $association)->where('name', $fields['body_name'])->first();
            if (! $body) {
                $bodyId = (string) Str::uuid();
                DB::table('association_governance_bodies')->insert(['id' => $bodyId, 'association_id' => $association, 'name' => $fields['body_name'], 'body_type' => $fields['body_type'], 'created_at' => now(), 'updated_at' => now()]);
                $body = (object) ['id' => $bodyId];
            } else {
                DB::table('association_governance_bodies')->where('id', $body->id)->update(['body_type' => $fields['body_type'], 'updated_at' => now()]);
            }
            foreach ($fields['positions'] ?? [] as $order => $position) {
                $existing = DB::table('association_governance_positions')->where('association_id', $association)->where('body_id', $body->id)->where('title', $position['title'])->first();
                $values = ['seats' => $position['seats'], 'display_order' => $position['display_order'] ?? ($order + 1), 'responsibilities' => $position['responsibilities'] ?? null, 'office_bearer' => (bool) ($position['office_bearer'] ?? false), 'voting_eligible' => (bool) ($position['voting_eligible'] ?? true), 'active' => true, 'updated_at' => now()];
                if ($existing) {
                    DB::table('association_governance_positions')->where('id', $existing->id)->update($values);
                } else {
                    DB::table('association_governance_positions')->insert([...$values, 'id' => (string) Str::uuid(), 'association_id' => $association, 'body_id' => $body->id, 'title' => $position['title'], 'created_at' => now()]);
                }
            }
            DB::table('association_governance_events')->insert(['association_id' => $association, 'actor_id' => $request->user()->getAuthIdentifier(), 'entity_type' => 'position', 'entity_id' => $body->id, 'action' => 'positions_saved', 'snapshot' => json_encode($fields, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });

        return back()->with('success', 'Governing body and positions saved.');
    }

    public function savePolicy(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate([
            'body_name' => ['required', 'string', 'max:160'],
            'policy' => ['required', 'array'],
            'policy.effective_from' => ['required', 'date'],
            'policy.timezone' => ['required', 'timezone'],
            'policy.quorum_type' => [Rule::in(['fixed_count', 'percentage'])],
            'policy.quorum_count' => ['nullable', 'integer', 'min:1'],
            'policy.quorum_percent' => ['nullable', 'numeric', 'gt:0', 'lte:100'],
            'policy.quorum_denominator' => [Rule::in(['eligible_seats', 'serving_appointments', 'attending_invited'])],
            'policy.rounding_policy' => [Rule::in(['ceil', 'floor', 'round'])],
            'policy.voting_rule' => [Rule::in(['ordinary_majority', 'percentage', 'unanimity'])],
            'policy.majority_percent' => ['nullable', 'numeric', 'gt:50', 'lte:100'],
            'policy.notice_days' => ['required', 'integer', 'min:0', 'max:365'],
            'policy.default_term_duration' => ['nullable', Rule::in(['1_year', '2_years', '3_years', 'custom'])],
            'policy.custom_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'policy.max_consecutive_terms' => ['nullable', 'integer', 'min:1', 'max:99'],
            'policy.allow_manual_determination' => ['boolean'],
            'policy.allow_external_appointees' => ['boolean'],
            'policy.policy_reference' => ['nullable', 'string', 'max:240'],
            'policy.reviewed' => ['boolean'],
        ]);
        if ($fields['policy']['quorum_type'] === 'fixed_count' && empty($fields['policy']['quorum_count'])) {
            return back()->withErrors(['policy.quorum_count' => 'Fixed-count quorum requires a count.']);
        }
        if ($fields['policy']['quorum_type'] === 'percentage' && empty($fields['policy']['quorum_percent'])) {
            return back()->withErrors(['policy.quorum_percent' => 'Percentage quorum requires a percentage.']);
        }
        if ($fields['policy']['voting_rule'] === 'percentage' && empty($fields['policy']['majority_percent'])) {
            return back()->withErrors(['policy.majority_percent' => 'Percentage voting requires a threshold.']);
        }
        if (($fields['policy']['default_term_duration'] ?? null) === 'custom' && empty($fields['policy']['custom_term_months'])) {
            return back()->withErrors(['policy.custom_term_months' => 'Custom term duration requires months.']);
        }
        $body = DB::table('association_governance_bodies')->where('association_id', $association)->where('name', $fields['body_name'])->firstOrFail();
        $policy = $fields['policy'];
        $latest = DB::table('association_governance_policies')->where('association_id', $association)->where('body_id', $body->id)->max('version') ?? 0;
        DB::table('association_governance_policies')->insert(['id' => (string) Str::uuid(), 'association_id' => $association, 'body_id' => $body->id, 'version' => $latest + 1, 'status' => ($policy['reviewed'] ?? false) ? 'reviewed' : 'draft', 'effective_from' => $policy['effective_from'], 'timezone' => $policy['timezone'], 'quorum_type' => $policy['quorum_type'], 'quorum_count' => $policy['quorum_count'] ?? null, 'quorum_percent' => $policy['quorum_percent'] ?? null, 'quorum_denominator' => $policy['quorum_denominator'], 'rounding_policy' => $policy['rounding_policy'], 'voting_rule' => $policy['voting_rule'], 'majority_percent' => $policy['majority_percent'] ?? null, 'notice_days' => $policy['notice_days'], 'default_term_duration' => $policy['default_term_duration'] ?? null, 'custom_term_months' => $policy['custom_term_months'] ?? null, 'max_consecutive_terms' => $policy['max_consecutive_terms'] ?? null, 'allow_manual_determination' => (bool) ($policy['allow_manual_determination'] ?? false), 'allow_external_appointees' => (bool) ($policy['allow_external_appointees'] ?? false), 'policy_reference' => $policy['policy_reference'] ?? null, 'reviewed_on' => ($policy['reviewed'] ?? false) ? now()->toDateString() : null, 'reviewed_by' => ($policy['reviewed'] ?? false) ? $request->user()->getAuthIdentifier() : null, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Reviewed policy saved as a new version.');
    }

    public function storeTerm(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['body_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:160'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'appointment_reference' => ['nullable', 'string', 'max:240'], 'policy_id' => ['nullable', 'uuid']]);
        DB::transaction(function () use ($request, $association, $fields): void {
            $this->body($association, $fields['body_id']);
            $policy = ! empty($fields['policy_id']) ? DB::table('association_governance_policies')->where('association_id', $association)->where('id', $fields['policy_id'])->first() : null;
            if ($fields['policy_id'] && ! $policy) {
                abort(422, 'Policy does not belong to this association.');
            }
            $id = (string) Str::uuid();
            DB::table('association_governance_terms')->insert([...$fields, 'id' => $id, 'association_id' => $association, 'state' => 'draft', 'created_by' => $request->user()->getAuthIdentifier(), 'created_at' => now(), 'updated_at' => now()]);
            $this->event($request, $association, 'term', $id, 'created', $fields);
        });

        return back()->with('success', 'Board term created.');
    }

    public function updateTerm(Request $request, string $association, string $term): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['state' => [Rule::in(['draft', 'scheduled', 'active', 'completed', 'cancelled'])], 'reason' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $association, $term, $fields): void {
            $record = DB::table('association_governance_terms')->where('association_id', $association)->where('id', $term)->lockForUpdate()->first();
            abort_if(! $record, 404);
            if ($fields['state'] === 'active') {
                DB::table('association_governance_bodies')->where('association_id', $association)->where('id', $record->body_id)->lockForUpdate()->first();
                DB::table('association_governance_terms')->where('association_id', $association)->where('body_id', $record->body_id)->where('state', 'active')->where('id', '!=', $term)->lockForUpdate()->get();
                DB::table('association_governance_terms')->where('association_id', $association)->where('body_id', $record->body_id)->where('state', 'active')->where('id', '!=', $term)->update(['state' => 'completed', 'notes' => 'Automatically completed when a newer term was activated.', 'updated_at' => now()]);
            }
            $allowed = ['draft' => ['scheduled', 'cancelled'], 'scheduled' => ['active', 'cancelled'], 'active' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => []];
            abort_unless(in_array($fields['state'], $allowed[$record->state] ?? [], true), 422, 'This term transition is not allowed.');
            if ($fields['state'] === 'active') {
                abort_unless(now()->toDateString() >= $record->starts_on, 422, 'A term cannot become active before its start date.');
            }
            DB::table('association_governance_terms')->where('id', $term)->update(['state' => $fields['state'], 'notes' => $fields['reason'] ?? $record->notes, 'updated_at' => now()]);
            $this->event($request, $association, 'term', $term, 'state_changed', $fields);
        });

        return back()->with('success', 'Term state updated.');
    }

    public function storeAppointment(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['body_id' => ['required', 'uuid'], 'term_id' => ['required', 'uuid'], 'position_id' => ['required', 'uuid'], 'member_id' => ['nullable', 'integer'], 'external_name' => ['nullable', 'string', 'max:160'], 'appointment_method' => ['required', 'string', 'max:40'], 'seat_number' => ['required', 'integer', 'min:1'], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on']]);
        abort_unless($fields['member_id'] || $fields['external_name'], 422, 'Select an association member or provide an approved external appointee.');
        DB::transaction(function () use ($request, $association, $fields): void {
            $term = DB::table('association_governance_terms')->where('association_id', $association)->where('id', $fields['term_id'])->lockForUpdate()->first();
            $position = DB::table('association_governance_positions')->where('association_id', $association)->where('id', $fields['position_id'])->lockForUpdate()->first();
            abort_unless($term && $position && $term->body_id === $fields['body_id'] && $position->body_id === $fields['body_id'], 422, 'Term and position must belong to the selected governing body.');
            if ($fields['member_id']) {
                abort_unless(DB::table('association_memberships')->where('association_id', $association)->where('member_id', $fields['member_id'])->exists(), 422, 'The selected person is not a member of this association.');
                abort_unless(! DB::table('association_governance_appointments')->where('association_id', $association)->where('member_id', $fields['member_id'])->whereIn('status', ['scheduled', 'serving'])->whereDate('starts_on', '<=', $fields['ends_on'] ?? '9999-12-31')->where(function ($query) use ($fields): void {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $fields['starts_on']);
                })->exists(), 422, 'This member already holds an overlapping Board appointment.');
            } else {
                $policy = DB::table('association_governance_policies')->where('association_id', $association)->where('body_id', $term->body_id)->where('status', 'reviewed')->orderByDesc('effective_from')->orderByDesc('version')->first();
                abort_unless($policy?->allow_external_appointees === true, 422, 'External appointees require an explicitly reviewed policy setting.');
            }
            abort_unless((int) $fields['seat_number'] <= (int) $position->seats, 422, 'The selected seat is outside this position capacity.');
            abort_unless(! DB::table('association_governance_appointments')->where('association_id', $association)->where('term_id', $term->id)->where('position_id', $position->id)->where('seat_number', $fields['seat_number'])->whereIn('status', ['scheduled', 'serving'])->exists(), 422, 'The selected seat is already occupied.');
            $count = DB::table('association_governance_appointments')->where('association_id', $association)->where('term_id', $term->id)->where('position_id', $position->id)->whereIn('status', ['scheduled', 'serving'])->count();
            abort_unless($count < $position->seats, 422, 'All seats for this position are occupied.');
            $id = (string) Str::uuid();
            DB::table('association_governance_appointments')->insert([...$fields, 'id' => $id, 'association_id' => $association, 'status' => 'scheduled', 'created_by' => $request->user()->getAuthIdentifier(), 'created_at' => now(), 'updated_at' => now()]);
            $this->event($request, $association, 'appointment', $id, 'created', $fields);
        });

        return back()->with('success', 'Appointment recorded.');
    }

    public function updateAppointment(Request $request, string $association, string $appointment): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['status' => [Rule::in(['scheduled', 'serving', 'resigned', 'removed', 'completed'])], 'effective_on' => ['required', 'date'], 'reason' => ['required', 'string', 'max:4000']]);
        DB::transaction(function () use ($request, $association, $appointment, $fields): void {
            $record = DB::table('association_governance_appointments')->where('association_id', $association)->where('id', $appointment)->lockForUpdate()->firstOrFail();
            abort_unless($fields['effective_on'] >= $record->starts_on, 422, 'The end date cannot be before the appointment start date.');
            $allowed = ['scheduled' => ['serving', 'removed'], 'serving' => ['resigned', 'removed', 'completed'], 'resigned' => [], 'removed' => [], 'completed' => []];
            abort_unless(in_array($fields['status'], $allowed[$record->status] ?? [], true), 422, 'This appointment transition is not allowed.');
            DB::table('association_governance_appointments')->where('id', $appointment)->update(['status' => $fields['status'], 'change_effective_on' => $fields['effective_on'], 'change_reason' => $fields['reason'], 'handover_status' => in_array($fields['status'], ['resigned', 'removed'], true) ? 'required' : $record->handover_status, 'updated_at' => now()]);
            DB::table('association_governance_appointment_history')->insert(['association_id' => $association, 'appointment_id' => $appointment, 'action' => $fields['status'], 'effective_on' => $fields['effective_on'], 'reason' => $fields['reason'], 'snapshot' => json_encode($record, JSON_THROW_ON_ERROR), 'actor_id' => $request->user()->getAuthIdentifier(), 'created_at' => now()]);
            $this->event($request, $association, 'appointment', $appointment, 'state_changed', $fields);
        });

        return back()->with('success', 'Appointment lifecycle updated.');
    }

    public function storeMeeting(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['body_id' => ['required', 'uuid'], 'term_id' => ['required', 'uuid'], 'meeting_type' => [Rule::in(['regular', 'special', 'agm'])], 'title' => ['required', 'string', 'max:240'], 'purpose' => ['nullable', 'string', 'max:5000'], 'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'timezone' => ['required', 'timezone'], 'mode' => [Rule::in(['physical', 'online', 'hybrid'])], 'venue' => ['nullable', 'string', 'max:240'], 'joining_details' => ['nullable', 'string', 'max:4000'], 'visibility' => [Rule::in(['administrators', 'members', 'public'])]]);
        DB::transaction(function () use ($request, $association, $fields): void {
            $this->body($association, $fields['body_id']);
            $term = DB::table('association_governance_terms')->where('association_id', $association)->where('id', $fields['term_id'])->firstOrFail();
            abort_unless($term->body_id === $fields['body_id'], 422, 'The meeting term must belong to the selected governing body.');
            $policy = DB::table('association_governance_policies')->where('association_id', $association)->where('body_id', $fields['body_id'])->where('status', 'reviewed')->whereDate('effective_from', '<=', substr($fields['starts_at'], 0, 10))->orderByDesc('effective_from')->orderByDesc('version')->first();
            $lock = DB::table('association_registration_applications')->where('id', $association)->lockForUpdate()->first();
            $number = DB::table('association_governance_meetings')->where('association_id', $association)->count() + 1;
            $id = (string) Str::uuid();
            if (! empty($fields['joining_details'])) {
                $fields['joining_details'] = Crypt::encryptString($fields['joining_details']);
            }
            DB::table('association_governance_meetings')->insert([...$fields, 'id' => $id, 'association_id' => $association, 'reference' => 'BOARD-'.now()->format('Y').'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'policy_id' => $policy?->id, 'policy_snapshot' => json_encode($policy ?? ['reviewed' => false], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $meetingDate = substr($fields['starts_at'], 0, 10);
            $appointments = DB::table('association_governance_appointments')->where('association_id', $association)->where('term_id', $term->id)->whereNotNull('member_id')->whereIn('status', ['scheduled', 'serving'])->whereDate('starts_on', '<=', $meetingDate)->where(function ($query) use ($meetingDate): void {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $meetingDate);
            })->get();
            foreach ($appointments as $appointment) {
                DB::table('association_governance_participants')->insertOrIgnore(['id' => BoardGovernance::uuid(), 'association_id' => $association, 'meeting_id' => $id, 'appointment_id' => $appointment->id, 'member_id' => $appointment->member_id, 'capacity' => 'member', 'attendance_status' => 'absent', 'voting_eligible' => (bool) $appointment->voting_eligible, 'created_at' => now(), 'updated_at' => now()]);
            }
            BoardGovernance::recheckQuorum($association, $id, (int) $request->user()->getAuthIdentifier());
            $this->event($request, $association, 'meeting', $id, 'created', ['term_id' => $term->id, 'policy_id' => $policy?->id]);
        });

        return back()->with('success', 'Meeting scheduled.');
    }

    public function sendMeetingNotice(Request $request, string $association, string $meeting): RedirectResponse
    {
        $this->owner($request, $association);
        DB::transaction(function () use ($association, $meeting): void {
            $record = DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->firstOrFail();
            $participants = DB::table('association_governance_participants')->where('association_id', $association)->where('meeting_id', $meeting)->whereNotNull('member_id')->pluck('member_id');
            foreach ($participants as $recipient) {
                BoardNotifications::queue($association, 'meeting_notice', 'meeting:'.$meeting.':notice', (int) $recipient, 'Board meeting notice: '.$record->title, 'Meeting '.$record->reference.' is scheduled for '.$record->starts_at.'. Joining details are available in the protected Board workspace.');
            }
            DB::table('association_governance_meetings')->where('id', $meeting)->update(['notice_state' => 'sent', 'updated_at' => now()]);
        });

        return back()->with('success', 'Meeting notices queued with duplicate suppression.');
    }

    public function storeParticipant(Request $request, string $association, string $meeting): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['appointment_id' => ['nullable', 'uuid'], 'guest_name' => ['nullable', 'string', 'max:160'], 'capacity' => [Rule::in(['member', 'guest', 'observer'])]]);
        abort_unless($fields['appointment_id'] || $fields['guest_name'], 422, 'A board appointment or guest name is required.');
        DB::transaction(function () use ($request, $association, $meeting, $fields): void {
            $meetingRecord = DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->lockForUpdate()->firstOrFail();
            $eligible = false;
            $memberId = null;
            if ($fields['appointment_id']) {
                $appointment = DB::table('association_governance_appointments as appointment')->join('association_governance_positions as position', 'position.id', '=', 'appointment.position_id')->where('appointment.association_id', $association)->where('appointment.id', $fields['appointment_id'])->where('appointment.term_id', $meetingRecord->term_id)->first();
                abort_unless($appointment, 422, 'The appointment does not belong to this meeting term.');
                $eligible = (bool) $appointment->voting_eligible;
                $memberId = $appointment->member_id;
            }
            $id = BoardGovernance::uuid();
            DB::table('association_governance_participants')->insert(['id' => $id, 'association_id' => $association, 'meeting_id' => $meeting, 'appointment_id' => $fields['appointment_id'] ?? null, 'member_id' => $memberId, 'guest_name' => $fields['guest_name'] ?? null, 'capacity' => $fields['capacity'] ?? 'member', 'voting_eligible' => $eligible, 'created_at' => now(), 'updated_at' => now()]);
            $this->event($request, $association, 'participant', $id, 'invited', $fields);
        });

        return back()->with('success', 'Meeting participant invited.');
    }

    public function updateParticipant(Request $request, string $association, string $participant): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['attendance_status' => [Rule::in(['invited', 'present', 'absent', 'excused'])], 'attendance_mode' => [Rule::in(['physical', 'online', 'hybrid'])], 'arrived_at' => ['nullable', 'date'], 'departed_at' => ['nullable', 'date', 'after_or_equal:arrived_at']]);
        DB::transaction(function () use ($request, $association, $participant, $fields): void {
            $record = DB::table('association_governance_participants')->where('association_id', $association)->where('id', $participant)->lockForUpdate()->firstOrFail();
            DB::table('association_governance_participants')->where('id', $participant)->update([...$fields, 'updated_at' => now()]);
            BoardGovernance::recheckQuorum($association, $record->meeting_id, (int) $request->user()->getAuthIdentifier());
            $this->event($request, $association, 'participant', $participant, 'attendance_updated', $fields);
        });

        return back()->with('success', 'Attendance updated and quorum rechecked.');
    }

    public function storeAgendaItem(Request $request, string $association, string $meeting): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['subject' => ['required', 'string', 'max:240'], 'background' => ['nullable', 'string', 'max:10000'], 'motion_text' => ['nullable', 'string', 'max:10000']]);
        DB::transaction(function () use ($request, $association, $meeting, $fields): void {
            DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->lockForUpdate()->firstOrFail();
            abort_unless(! DB::table('association_governance_minutes_versions')->where('association_id', $association)->where('meeting_id', $meeting)->whereIn('state', ['approved', 'published'])->exists(), 422, 'Approved minutes are locked. Create an amendment before changing resolutions.');
            $sequence = (int) DB::table('association_governance_agenda_items')->where('association_id', $association)->where('meeting_id', $meeting)->max('sequence') + 1;
            $id = BoardGovernance::uuid();
            $eligible = DB::table('association_governance_participants')->where('association_id', $association)->where('meeting_id', $meeting)->where('voting_eligible', true)->where('attendance_status', 'present')->pluck('id')->values()->all();
            DB::table('association_governance_agenda_items')->insert([...$fields, 'id' => $id, 'association_id' => $association, 'meeting_id' => $meeting, 'sequence' => $sequence, 'eligible_snapshot' => json_encode($eligible, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $this->event($request, $association, 'agenda_item', $id, 'created', $fields);
        });

        return back()->with('success', 'Agenda item added.');
    }

    public function recordVote(Request $request, string $association, string $meeting, string $agendaItem): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['participant_id' => ['required', 'uuid'], 'choice' => [Rule::in(['for', 'against', 'abstain', 'recused'])], 'note' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $association, $meeting, $agendaItem, $fields): void {
            $meetingRecord = DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->lockForUpdate()->firstOrFail();
            abort_unless(! DB::table('association_governance_minutes_versions')->where('association_id', $association)->where('meeting_id', $meeting)->whereIn('state', ['approved', 'published'])->exists(), 422, 'Approved minutes are locked. Create an amendment before changing resolutions.');
            abort_unless($meetingRecord->quorum_state === 'passed', 422, 'A passed quorum check is required before votes can be recorded.');
            $participant = DB::table('association_governance_participants')->where('association_id', $association)->where('meeting_id', $meeting)->where('id', $fields['participant_id'])->firstOrFail();
            abort_unless($participant->voting_eligible && $participant->attendance_status === 'present', 422, 'Only present voting participants may vote or recuse.');
            DB::table('association_governance_agenda_items')->where('association_id', $association)->where('meeting_id', $meeting)->where('id', $agendaItem)->firstOrFail();
            DB::table('association_governance_votes')->updateOrInsert(['association_id' => $association, 'agenda_item_id' => $agendaItem, 'participant_id' => $participant->id], ['id' => BoardGovernance::uuid(), 'meeting_id' => $meeting, 'choice' => $fields['choice'], 'note' => $fields['note'] ?? null, 'recorded_by' => $request->user()->getAuthIdentifier(), 'updated_at' => now(), 'created_at' => now()]);
            $this->event($request, $association, 'agenda_item', $agendaItem, 'vote_recorded', ['participant_id' => $participant->id, 'choice' => $fields['choice']]);
        });

        return back()->with('success', 'Vote recorded.');
    }

    public function finalizeDecision(Request $request, string $association, string $meeting, string $agendaItem): RedirectResponse
    {
        $this->owner($request, $association);
        DB::transaction(function () use ($request, $association, $meeting, $agendaItem): void {
            abort_unless(! DB::table('association_governance_minutes_versions')->where('association_id', $association)->where('meeting_id', $meeting)->whereIn('state', ['approved', 'published'])->exists(), 422, 'Approved minutes are locked. Create an amendment before changing resolutions.');
            $result = BoardGovernance::finalizeDecision($association, $meeting, $agendaItem, (int) $request->user()->getAuthIdentifier());
            $this->event($request, $association, 'agenda_item', $agendaItem, 'decision_finalized', (array) $result);
        });

        return back()->with('success', 'Decision finalized.');
    }

    public function updateMinutes(Request $request, string $association, string $meeting): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['action' => [Rule::in(['save', 'submit', 'request_changes', 'amend', 'approve', 'publish'])], 'content' => ['required', 'array'], 'redacted_content' => ['nullable', 'array'], 'comment' => ['nullable', 'string', 'max:4000'], 'base_version' => ['nullable', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $association, $meeting, $fields): void {
            $record = DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $meeting)->lockForUpdate()->firstOrFail();
            $latest = DB::table('association_governance_minutes_versions')->where('association_id', $association)->where('meeting_id', $meeting)->orderByDesc('version')->lockForUpdate()->first();
            if (isset($fields['base_version'])) {
                abort_unless($latest && (int) $latest->version === (int) $fields['base_version'], 409, 'Minutes changed in another session. Reload the latest version before saving.');
            }
            if (in_array($fields['action'], ['request_changes', 'approve', 'publish'], true)) {
                $this->assertReviewer($request, $association, $latest, $fields['action']);
            }
            if ($fields['action'] === 'approve') {
                abort_unless($latest && $latest->state === 'submitted', 422, 'Only submitted minutes can be approved.');
            }
            if ($fields['action'] === 'publish') {
                abort_unless($latest && $latest->state === 'approved', 422, 'Only approved minutes can be published.');
            }
            if (in_array($fields['action'], ['save', 'submit'], true)) {
                abort_unless(! $latest || ! in_array($latest->state, ['approved', 'published'], true), 422, 'Approved minutes are immutable. Start an amendment instead.');
            }
            if ($fields['action'] === 'amend') {
                abort_unless($latest && in_array($latest->state, ['approved', 'published'], true), 422, 'Only approved minutes can be amended.');
            }
            $state = match ($fields['action']) {
                'submit' => 'submitted', 'request_changes' => 'changes_requested', 'approve' => 'approved', 'publish' => 'published', default => 'draft'
            };
            $source = in_array($fields['action'], ['approve', 'publish', 'request_changes'], true) && $latest ? $latest : null;
            $content = $source ? json_decode($source->content, true, flags: JSON_THROW_ON_ERROR) : $fields['content'];
            $hash = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
            $id = (string) Str::uuid();
            DB::table('association_governance_minutes_versions')->insert(['id' => $id, 'association_id' => $association, 'meeting_id' => $meeting, 'version' => ($latest?->version ?? 0) + 1, 'state' => $state, 'content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_hash' => $hash, 'redacted_content' => ! empty($fields['redacted_content']) ? json_encode($fields['redacted_content'], JSON_THROW_ON_ERROR) : null, 'supersedes_version_id' => in_array($fields['action'], ['amend', 'publish'], true) ? $latest?->id : null, 'is_amendment' => $fields['action'] === 'amend', 'prepared_by' => $latest?->prepared_by ?? $request->user()->getAuthIdentifier(), 'approved_by' => in_array($fields['action'], ['approve', 'publish'], true) ? $request->user()->getAuthIdentifier() : null, 'submitted_at' => in_array($fields['action'], ['submit', 'approve', 'publish'], true) ? now() : null, 'approved_at' => in_array($fields['action'], ['approve', 'publish'], true) ? now() : null, 'published_at' => $fields['action'] === 'publish' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('association_governance_minutes_approvals')->insert(['association_id' => $association, 'minutes_version_id' => $id, 'actor_id' => $request->user()->getAuthIdentifier(), 'action' => $fields['action'], 'comment' => $fields['comment'] ?? null, 'created_at' => now()]);
            DB::table('association_governance_meetings')->where('id', $meeting)->update(['minutes_state' => $state, 'updated_at' => now()]);
            $this->event($request, $association, 'minutes', $id, $fields['action'], ['meeting_id' => $meeting, 'comment' => $fields['comment'] ?? null]);
        });

        return back()->with('success', 'Minutes version saved.');
    }

    public function storeAction(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['description' => ['required', 'string', 'max:500'], 'due_on' => ['nullable', 'date'], 'priority' => [Rule::in(['low', 'medium', 'normal', 'high', 'urgent'])], 'meeting_id' => ['nullable', 'uuid'], 'agenda_item_id' => ['nullable', 'uuid'], 'assignee_member_id' => ['nullable', 'integer']]);
        DB::transaction(function () use ($association, $fields): void {
            if (! empty($fields['meeting_id'])) {
                abort_unless(DB::table('association_governance_meetings')->where('association_id', $association)->where('id', $fields['meeting_id'])->exists(), 422, 'Meeting does not belong to this association.');
            }
            if (! empty($fields['agenda_item_id'])) {
                $agenda = DB::table('association_governance_agenda_items')->where('association_id', $association)->where('id', $fields['agenda_item_id'])->first();
                abort_unless($agenda, 422, 'Agenda item does not belong to this association.');
                abort_unless(! empty($fields['meeting_id']) && $agenda->meeting_id === $fields['meeting_id'], 422, 'The resolution must belong to the selected meeting.');
            }
            if (! empty($fields['assignee_member_id'])) {
                abort_unless(DB::table('association_governance_appointments')->where('association_id', $association)->where('member_id', $fields['assignee_member_id'])->whereIn('status', ['scheduled', 'serving'])->exists(), 422, 'The assignee must be an active board appointee in this association.');
            }
            DB::table('association_governance_actions')->insert([...$fields, 'id' => (string) Str::uuid(), 'association_id' => $association, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Action created.');
    }

    public function addReviewer(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['user_id' => ['required', 'integer'], 'role' => [Rule::in(['approver', 'publisher'])]]);
        abort_unless(DB::table('users')->where('id', $fields['user_id'])->exists(), 422, 'Reviewer account was not found.');
        DB::table('association_governance_reviewers')->updateOrInsert(['association_id' => $association, 'user_id' => $fields['user_id'], 'role' => $fields['role']], ['active' => true, 'updated_at' => now(), 'created_at' => now()]);

        return back()->with('success', 'Board reviewer configured.');
    }

    public function updateGovernanceSettings(Request $request, string $association): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['allow_self_approval' => ['boolean'], 'directory_visibility' => [Rule::in(['administrators', 'members', 'public'])], 'public_directory_enabled' => ['boolean']]);
        DB::table('association_governance_settings')->updateOrInsert(['association_id' => $association], [...$fields, 'updated_at' => now(), 'created_at' => now()]);

        return back()->with('success', 'Board governance settings saved.');
    }

    public function updateAction(Request $request, string $association, string $action): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['status' => [Rule::in(['open', 'in_progress', 'blocked', 'completed', 'cancelled'])], 'progress_comment' => ['nullable', 'string', 'max:4000'], 'completion_evidence' => ['nullable', 'string', 'max:4000']]);
        DB::table('association_governance_actions')->where('association_id', $association)->where('id', $action)->update([...$fields, 'updated_at' => now()]);

        return back()->with('success', 'Action updated.');
    }

    public function reassignAction(Request $request, string $association, string $action): RedirectResponse
    {
        $this->owner($request, $association);
        $fields = $request->validate(['assignee_appointment_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $association, $action, $fields): void {
            $current = DB::table('association_governance_actions')->where('association_id', $association)->where('id', $action)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('association_governance_appointments')->where('association_id', $association)->where('id', $fields['assignee_appointment_id'])->exists(), 422, 'The replacement assignee is outside this association.');
            DB::table('association_governance_actions')->where('id', $action)->update(['assignee_appointment_id' => $fields['assignee_appointment_id'], 'assignee_member_id' => null, 'updated_at' => now()]);
            DB::table('association_governance_action_history')->insert(['association_id' => $association, 'action_id' => $action, 'action' => 'reassigned', 'from_appointment_id' => $current->assignee_appointment_id, 'to_appointment_id' => $fields['assignee_appointment_id'], 'reason' => $fields['reason'], 'actor_id' => $request->user()->getAuthIdentifier(), 'created_at' => now()]);
            $this->event($request, $association, 'action', $action, 'reassigned', $fields);
        });

        return back()->with('success', 'Outstanding action reassigned with history retained.');
    }

    private function owner(Request $request, string $association): void
    {
        WorkspaceProtection::owner($request, $association);
    }

    private function body(string $association, string $id): object
    {
        return DB::table('association_governance_bodies')->where('association_id', $association)->where('id', $id)->firstOrFail();
    }

    private function event(Request $request, string $association, string $type, string $id, string $action, array $snapshot): void
    {
        DB::table('association_governance_events')->insert(['association_id' => $association, 'actor_id' => $request->user()->getAuthIdentifier(), 'entity_type' => $type, 'entity_id' => $id, 'action' => $action, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function assertReviewer(Request $request, string $association, ?object $latest, string $action): void
    {
        $role = $action === 'publish' ? 'publisher' : 'approver';
        abort_unless(DB::table('association_governance_reviewers')->where('association_id', $association)->where('user_id', $request->user()->getAuthIdentifier())->where('role', $role)->where('active', true)->exists(), 403, 'You are not an authorized Board minutes reviewer.');
        $settings = DB::table('association_governance_settings')->where('association_id', $association)->first();
        if ($latest && ! ($settings?->allow_self_approval ?? false)) {
            abort_unless((int) $latest->prepared_by !== (int) $request->user()->getAuthIdentifier(), 422, 'Self-approval is disabled for Board minutes.');
        }
    }

    private function data(string $association): array
    {
        $bodies = DB::table('association_governance_bodies')->where('association_id', $association)->orderBy('display_order')->orderBy('name')->get();
        $bodyIds = $bodies->pluck('id');

        return [
            'bodies' => $bodies,
            'positions' => DB::table('association_governance_positions')->where('association_id', $association)->whereIn('body_id', $bodyIds)->orderBy('display_order')->get(),
            'policies' => DB::table('association_governance_policies')->where('association_id', $association)->orderByDesc('effective_from')->orderByDesc('version')->get(),
            'terms' => DB::table('association_governance_terms as term')->join('association_governance_bodies as body', 'body.id', '=', 'term.body_id')->where('term.association_id', $association)->select('term.*', 'body.name as body_name')->orderByDesc('term.starts_on')->get(),
            'appointments' => DB::table('association_governance_appointments as appointment')->join('association_governance_positions as position', 'position.id', '=', 'appointment.position_id')->leftJoin('users as member', 'member.id', '=', 'appointment.member_id')->where('appointment.association_id', $association)->select('appointment.*', 'position.title as position_title', 'position.display_order', 'position.office_bearer', 'position.voting_eligible', 'member.name as member_name', 'member.email as member_email')->orderBy('position.display_order')->get(),
            'meetings' => DB::table('association_governance_meetings as meeting')->leftJoin('association_governance_terms as term', 'term.id', '=', 'meeting.term_id')->where('meeting.association_id', $association)->select('meeting.*', 'term.name as term_name')->orderByDesc('meeting.starts_at')->get(),
            'participants' => DB::table('association_governance_participants as participant')->leftJoin('association_governance_appointments as appointment', 'appointment.id', '=', 'participant.appointment_id')->leftJoin('association_governance_positions as position', 'position.id', '=', 'appointment.position_id')->leftJoin('users as member', 'member.id', '=', 'participant.member_id')->where('participant.association_id', $association)->select('participant.*', 'position.title as position_title', 'member.name as member_name', 'member.email as member_email')->orderBy('participant.created_at')->get(),
            'agendaItems' => DB::table('association_governance_agenda_items')->where('association_id', $association)->orderBy('meeting_id')->orderBy('sequence')->get(),
            'votes' => DB::table('association_governance_votes')->where('association_id', $association)->orderBy('created_at')->get(),
            'minutes' => DB::table('association_governance_minutes_versions')->where('association_id', $association)->orderByDesc('created_at')->get(),
            'reviewers' => DB::table('association_governance_reviewers as reviewer')->join('users as user', 'user.id', '=', 'reviewer.user_id')->where('reviewer.association_id', $association)->where('reviewer.active', true)->select('reviewer.*', 'user.name', 'user.email')->get(),
            'governanceSettings' => DB::table('association_governance_settings')->where('association_id', $association)->first(),
            'actions' => DB::table('association_governance_actions as action')->leftJoin('association_governance_meetings as meeting', 'meeting.id', '=', 'action.meeting_id')->leftJoin('association_governance_agenda_items as agenda', 'agenda.id', '=', 'action.agenda_item_id')->leftJoin('users as assignee', 'assignee.id', '=', 'action.assignee_member_id')->where('action.association_id', $association)->select('action.*', 'meeting.reference as meeting_reference', 'meeting.title as meeting_title', 'agenda.subject as resolution_subject', 'assignee.name as assignee_name')->orderByRaw("CASE WHEN action.status IN ('open','in_progress','blocked') THEN 0 ELSE 1 END")->orderBy('action.due_on')->get(),
            'members' => DB::table('association_memberships as membership')->join('users as member', 'member.id', '=', 'membership.member_id')->where('membership.association_id', $association)->where('membership.status', 'active')->where('membership.starts_at', '<=', now())->where(function ($query): void {
                $query->whereNull('membership.ends_at')->orWhere('membership.ends_at', '>', now());
            })->select('member.id', 'member.name', 'membership.number')->orderBy('member.name')->get(),
            'summary' => [
                'active_terms' => DB::table('association_governance_terms')->where('association_id', $association)->where('state', 'active')->count(),
                'vacancies' => DB::table('association_governance_positions as position')->leftJoin('association_governance_appointments as appointment', function ($join): void {
                    $join->on('appointment.position_id', '=', 'position.id')->whereIn('appointment.status', ['scheduled', 'serving']);
                })->where('position.association_id', $association)->selectRaw('coalesce(sum(position.seats), 0) - count(appointment.id) as vacancies')->value('vacancies'),
                'upcoming_meetings' => DB::table('association_governance_meetings')->where('association_id', $association)->where('starts_at', '>=', now())->whereNotIn('state', ['cancelled', 'closed'])->count(),
                'open_actions' => DB::table('association_governance_actions')->where('association_id', $association)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
                'minutes_review' => DB::table('association_governance_minutes_versions')->where('association_id', $association)->where('state', 'submitted')->count(),
            ],
        ];
    }
}
