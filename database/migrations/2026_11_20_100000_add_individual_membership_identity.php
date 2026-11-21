<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::table('association_membership_applications', fn (Blueprint $table) => $table->json('preapproval_plan_snapshot')->nullable());
        // Preserve earlier pre-review choices for audit, then require post-approval confirmation.
        DB::statement("UPDATE association_membership_applications AS application
            SET preapproval_plan_snapshot = json_build_object('membership_type', application.membership_type, 'fee_minor', application.fee_minor, 'currency', application.currency),
                membership_type = NULL, fee_minor = NULL, currency = NULL
            WHERE application.status IN ('pending', 'accepted_for_payment') AND application.plan_confirmed_at IS NULL
                AND application.membership_type IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM association_payment_requests AS payment WHERE payment.application_id = application.id)");
        Schema::create('filmunity_individual_memberships', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->string('passport_path')->nullable();
            $table->timestampTz('joined_at');
            $table->timestampsTz();
        });
        Schema::create('association_membership_card_tokens', function (Blueprint $table): void {
            $table->foreignUuid('membership_id')->primary()->constrained('association_memberships')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestampTz('created_at');
        });
        Schema::create('membership_correspondence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('association_membership_applications')->restrictOnDelete();
            $table->string('event', 40);
            $table->string('recipient');
            $table->json('snapshot');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['application_id', 'event', 'recipient'], 'membership_correspondence_event_unique');
        });
    }
    public function down(): void { throw new RuntimeException('Membership identities and correspondence history are retained. Restore a verified backup to revert.'); }
};
