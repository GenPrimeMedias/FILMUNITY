<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_governance_meetings', function (Blueprint $table): void {
            $table->string('quorum_state', 24)->default('not_checked')->after('policy_snapshot');
            $table->unsignedInteger('quorum_required')->nullable()->after('quorum_state');
            $table->unsignedInteger('quorum_present')->nullable()->after('quorum_required');
            $table->unsignedInteger('quorum_denominator_value')->nullable()->after('quorum_present');
            $table->text('quorum_manual_reason')->nullable()->after('quorum_denominator_value');
            $table->foreignId('quorum_confirmed_by')->nullable()->after('quorum_manual_reason')->constrained('users')->nullOnDelete();
            $table->timestampTz('quorum_confirmed_at')->nullable()->after('quorum_confirmed_by');
        });

        Schema::create('association_governance_votes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('meeting_id')->constrained('association_governance_meetings')->restrictOnDelete();
            $table->foreignUuid('agenda_item_id')->constrained('association_governance_agenda_items')->restrictOnDelete();
            $table->foreignUuid('participant_id')->constrained('association_governance_participants')->restrictOnDelete();
            $table->string('choice', 16);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['association_id', 'agenda_item_id', 'participant_id'], 'governance_vote_participant_unique');
            $table->index(['association_id', 'meeting_id', 'agenda_item_id']);
        });

        Schema::create('association_governance_appointment_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('appointment_id')->constrained('association_governance_appointments')->restrictOnDelete();
            $table->string('action', 32);
            $table->date('effective_on')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('snapshot')->default('{}');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['association_id', 'appointment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association_governance_appointment_history');
        Schema::dropIfExists('association_governance_votes');
        Schema::table('association_governance_meetings', function (Blueprint $table): void {
            $table->dropForeign(['quorum_confirmed_by']);
            $table->dropColumn(['quorum_state', 'quorum_required', 'quorum_present', 'quorum_denominator_value', 'quorum_manual_reason', 'quorum_confirmed_by', 'quorum_confirmed_at']);
        });
    }
};
