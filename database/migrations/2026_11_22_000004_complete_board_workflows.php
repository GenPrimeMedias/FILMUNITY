<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_governance_appointments', function (Blueprint $table): void {
            $table->string('portrait_path', 500)->nullable();
            $table->string('handover_status', 24)->default('not_required');
        });

        Schema::table('association_governance_minutes_versions', function (Blueprint $table): void {
            $table->string('content_hash', 64)->nullable();
            $table->foreignUuid('supersedes_version_id')->nullable()->constrained('association_governance_minutes_versions')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_amendment')->default(false);
            $table->index(['association_id', 'meeting_id', 'content_hash']);
        });

        Schema::create('association_governance_reviewers', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 24)->default('approver');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['association_id', 'user_id', 'role'], 'governance_reviewer_unique');
        });

        Schema::create('association_governance_settings', function (Blueprint $table): void {
            $table->foreignUuid('association_id')->primary()->constrained('association_registration_applications')->restrictOnDelete();
            $table->string('minutes_reviewer_policy', 24)->default('configured');
            $table->boolean('allow_self_approval')->default(false);
            $table->string('directory_visibility', 24)->default('administrators');
            $table->boolean('public_directory_enabled')->default(false);
            $table->timestampsTz();
        });

        Schema::create('association_governance_action_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignUuid('action_id')->constrained('association_governance_actions')->restrictOnDelete();
            $table->string('action', 32);
            $table->foreignUuid('from_appointment_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->foreignUuid('to_appointment_id')->nullable()->constrained('association_governance_appointments')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['association_id', 'action_id', 'created_at']);
        });

        Schema::create('association_governance_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->string('kind', 48);
            $table->string('dedupe_key', 180);
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->string('subject', 240);
            $table->text('body');
            $table->string('state', 24)->default('pending');
            $table->timestampTz('sent_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['association_id', 'dedupe_key', 'recipient_id'], 'governance_notification_dedupe');
            $table->index(['association_id', 'state', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Board workflow history and delivery records are retained; use reviewed forward recovery.');
    }
};
