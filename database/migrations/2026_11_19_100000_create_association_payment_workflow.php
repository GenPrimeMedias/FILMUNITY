<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_membership_applications', function (Blueprint $table): void {
            $table->timestampTz('plan_confirmed_at')->nullable();
        });
        Schema::table('association_workspace_settings', function (Blueprint $table): void {
            $table->text('payment_instructions')->nullable();
        });
        Schema::create('association_payment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->unique()->constrained('association_membership_applications')->restrictOnDelete();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('membership_type', 12);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->text('instructions');
            $table->date('due_on')->nullable();
            $table->string('status', 24)->default('requested');
            $table->json('snapshot');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();
            $table->index(['association_id', 'status', 'created_at'], 'payment_owner_status_index');
            $table->index(['member_id', 'created_at'], 'payment_member_created_index');
        });
        Schema::create('association_payment_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('association_payment_requests')->restrictOnDelete();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->string('reference', 120);
            $table->string('reference_key', 120);
            $table->date('paid_on');
            $table->string('proof_path');
            $table->string('status', 20)->default('submitted');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->index(['payment_id', 'created_at'], 'proof_payment_created_index');
        });
        DB::statement("CREATE UNIQUE INDEX payment_reference_in_use_unique
            ON association_payment_submissions (association_id, reference_key)
            WHERE status IN ('submitted', 'accepted')");
        Schema::create('association_receipt_counters', function (Blueprint $table): void {
            $table->foreignUuid('association_id')->primary()->constrained('association_registration_applications')->restrictOnDelete();
            $table->unsignedBigInteger('sequence')->default(0);
        });
        Schema::create('association_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->unique()->constrained('association_payment_requests')->restrictOnDelete();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->string('number', 40);
            $table->string('membership_type', 12);
            $table->string('status', 20)->default('active');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
            $table->unique(['association_id', 'member_id'], 'membership_member_association_unique');
            $table->unique(['association_id', 'number'], 'membership_number_unique');
        });
        Schema::create('association_payment_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->unique()->constrained('association_payment_requests')->restrictOnDelete();
            $table->foreignUuid('membership_id')->unique()->constrained('association_memberships')->restrictOnDelete();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->string('number', 40);
            $table->json('snapshot');
            $table->timestampTz('issued_at');
            $table->unique(['association_id', 'number'], 'receipt_number_unique');
        });
        Schema::create('association_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('payment_id')->constrained('association_payment_requests')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->text('note')->nullable();
            $table->timestampTz('created_at');
        });
        Schema::create('association_payment_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('association_payment_requests')->restrictOnDelete();
            $table->string('event_key', 100);
            $table->string('recipient');
            $table->string('subject');
            $table->text('message');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['payment_id', 'event_key', 'recipient'], 'payment_notification_unique');
        });
    }

    public function down(): void
    {
        Schema::table('association_membership_applications', fn (Blueprint $table) => $table->dropColumn('plan_confirmed_at'));
        foreach (['association_payment_notifications', 'association_payment_events', 'association_payment_receipts',
            'association_memberships', 'association_receipt_counters', 'association_payment_submissions',
            'association_payment_requests'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('association_workspace_settings', fn (Blueprint $table) => $table->dropColumn('payment_instructions'));
    }
};
