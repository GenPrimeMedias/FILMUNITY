<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_governance_bodies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('body_type', 40)->default('board');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['association_id', 'name'], 'governance_body_name_unique');
            $table->index(['association_id', 'active', 'display_order']);
        });

        Schema::create('association_governance_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('body_id')->constrained('association_governance_bodies')->restrictOnDelete();
            $table->string('title', 160);
            $table->unsignedInteger('seats')->default(1);
            $table->unsignedInteger('display_order')->default(0);
            $table->text('responsibilities')->nullable();
            $table->boolean('office_bearer')->default(false);
            $table->boolean('voting_eligible')->default(true);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['association_id', 'body_id', 'title'], 'governance_position_title_unique');
            $table->unique(['association_id', 'id'], 'governance_position_tenant_unique');
            $table->index(['association_id', 'body_id', 'display_order']);
        });

        Schema::create('association_governance_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('body_id')->nullable()->constrained('association_governance_bodies')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->string('timezone', 80)->default('UTC');
            $table->string('quorum_type', 24)->default('fixed_count');
            $table->unsignedInteger('quorum_count')->nullable();
            $table->decimal('quorum_percent', 6, 3)->nullable();
            $table->string('quorum_denominator', 32)->default('eligible_seats');
            $table->string('rounding_policy', 24)->default('ceil');
            $table->boolean('include_vacancies')->default(false);
            $table->boolean('include_nonvoting')->default(false);
            $table->string('voting_rule', 24)->default('ordinary_majority');
            $table->decimal('majority_percent', 6, 3)->nullable();
            $table->boolean('allow_manual_determination')->default(false);
            $table->boolean('allow_proxy')->default(false);
            $table->unsignedInteger('notice_days')->default(0);
            $table->jsonb('workflow')->default('{}');
            $table->string('policy_reference', 240)->nullable();
            $table->date('reviewed_on')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('governing_document_path')->nullable();
            $table->timestampsTz();
            $table->unique(['association_id', 'body_id', 'version'], 'governance_policy_version_unique');
            $table->index(['association_id', 'body_id', 'status', 'effective_from']);
        });

        Schema::create('association_governance_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('body_id')->constrained('association_governance_bodies')->restrictOnDelete();
            $table->foreignUuid('policy_id')->nullable()->constrained('association_governance_policies')->nullOnDelete();
            $table->string('name', 160);
            $table->string('state', 20)->default('draft');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('appointment_reference', 240)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['association_id', 'body_id', 'name'], 'governance_term_name_unique');
            $table->index(['association_id', 'body_id', 'state', 'starts_on', 'ends_on']);
        });

        Schema::create('association_governance_appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('body_id')->constrained('association_governance_bodies')->restrictOnDelete();
            $table->foreignUuid('term_id')->constrained('association_governance_terms')->restrictOnDelete();
            $table->foreignUuid('position_id')->constrained('association_governance_positions')->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('external_name', 160)->nullable();
            $table->string('appointment_method', 40)->default('appointed');
            $table->unsignedInteger('seat_number')->default(1);
            $table->string('status', 20)->default('scheduled');
            $table->date('appointed_on')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('change_effective_on')->nullable();
            $table->text('change_reason')->nullable();
            $table->text('document_path')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['association_id', 'term_id', 'position_id', 'seat_number'], 'governance_appointment_seat_unique');
            $table->unique(['association_id', 'id'], 'governance_appointment_tenant_unique');
            $table->index(['association_id', 'term_id', 'status']);
            $table->index(['association_id', 'member_id', 'status']);
        });

        Schema::create('association_governance_meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('body_id')->constrained('association_governance_bodies')->restrictOnDelete();
            $table->foreignUuid('term_id')->constrained('association_governance_terms')->restrictOnDelete();
            $table->foreignUuid('policy_id')->nullable()->constrained('association_governance_policies')->nullOnDelete();
            $table->string('reference', 60);
            $table->string('meeting_type', 80)->default('regular');
            $table->string('title', 240);
            $table->text('purpose')->nullable();
            $table->string('state', 20)->default('draft');
            $table->string('notice_state', 20)->default('draft');
            $table->string('minutes_state', 20)->default('draft');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('timezone', 80);
            $table->string('mode', 20)->default('physical');
            $table->string('venue', 240)->nullable();
            $table->text('joining_details')->nullable();
            $table->foreignUuid('chair_appointment_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->foreignUuid('minutes_secretary_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->string('visibility', 24)->default('administrators');
            $table->jsonb('policy_snapshot')->default('{}');
            $table->jsonb('reschedule_history')->default('[]');
            $table->unsignedInteger('version')->default(1);
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['association_id', 'reference'], 'governance_meeting_reference_unique');
            $table->index(['association_id', 'body_id', 'term_id', 'starts_at']);
            $table->index(['association_id', 'state', 'minutes_state']);
        });

        Schema::create('association_governance_agenda_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('meeting_id')->constrained('association_governance_meetings')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('subject', 240);
            $table->text('background')->nullable();
            $table->foreignUuid('presenter_appointment_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->text('discussion_summary')->nullable();
            $table->text('motion_text')->nullable();
            $table->foreignUuid('proposer_appointment_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->foreignUuid('seconder_appointment_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->jsonb('eligible_snapshot')->default('[]');
            $table->unsignedInteger('for_count')->default(0);
            $table->unsignedInteger('against_count')->default(0);
            $table->unsignedInteger('abstained_count')->default(0);
            $table->unsignedInteger('recused_count')->default(0);
            $table->string('outcome', 20)->nullable();
            $table->text('dissent')->nullable();
            $table->text('chair_determination')->nullable();
            $table->string('resolution_number', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['association_id', 'meeting_id', 'sequence'], 'governance_agenda_sequence_unique');
            $table->unique(['association_id', 'resolution_number'], 'governance_resolution_number_unique');
            $table->index(['association_id', 'meeting_id']);
        });

        Schema::create('association_governance_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('meeting_id')->constrained('association_governance_meetings')->restrictOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name', 160)->nullable();
            $table->string('capacity', 40)->default('member');
            $table->string('invitation_status', 20)->default('invited');
            $table->string('attendance_status', 20)->default('absent');
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('departed_at')->nullable();
            $table->string('attendance_mode', 20)->nullable();
            $table->boolean('voting_eligible')->default(false);
            $table->timestampsTz();
            $table->unique(['association_id', 'meeting_id', 'appointment_id'], 'governance_participant_appointment_unique');
            $table->index(['association_id', 'meeting_id', 'attendance_status']);
        });

        Schema::create('association_governance_minutes_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('meeting_id')->constrained('association_governance_meetings')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 24)->default('draft');
            $table->jsonb('content')->default('{}');
            $table->jsonb('redacted_content')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['association_id', 'meeting_id', 'version'], 'governance_minutes_version_unique');
            $table->index(['association_id', 'state', 'published_at']);
        });

        Schema::create('association_governance_minutes_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('minutes_version_id')->constrained('association_governance_minutes_versions')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('comment')->nullable();
            $table->timestampTz('created_at');
            $table->index(['association_id', 'minutes_version_id', 'created_at']);
        });

        Schema::create('association_governance_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('meeting_id')->nullable()->constrained('association_governance_meetings')->nullOnDelete();
            $table->foreignUuid('agenda_item_id')->nullable()->constrained('association_governance_agenda_items')->nullOnDelete();
            $table->foreignUuid('assignee_appointment_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->foreignId('assignee_member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description', 500);
            $table->date('due_on')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->string('status', 20)->default('open');
            $table->text('progress_comment')->nullable();
            $table->text('completion_evidence')->nullable();
            $table->timestampsTz();
            $table->index(['association_id', 'status', 'due_on']);
            $table->index(['association_id', 'assignee_member_id', 'status']);
        });

        Schema::create('association_governance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type', 60);
            $table->uuid('entity_id');
            $table->string('action', 60);
            $table->jsonb('snapshot')->default('{}');
            $table->timestampTz('created_at');
            $table->index(['association_id', 'entity_type', 'entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'association_governance_events', 'association_governance_actions',
            'association_governance_minutes_approvals', 'association_governance_minutes_versions',
            'association_governance_participants', 'association_governance_agenda_items',
            'association_governance_meetings', 'association_governance_appointments',
            'association_governance_terms', 'association_governance_policies',
            'association_governance_positions', 'association_governance_bodies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
