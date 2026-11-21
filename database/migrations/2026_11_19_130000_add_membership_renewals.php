<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_payment_requests', function (Blueprint $table): void {
            $table->foreignUuid('renewal_membership_id')->nullable()->constrained('association_memberships')->restrictOnDelete();
            $table->timestampTz('renewal_from')->nullable();
            $table->dropUnique(['application_id']);
        });
        DB::statement('CREATE UNIQUE INDEX payment_initial_application_unique ON association_payment_requests (application_id) WHERE renewal_membership_id IS NULL');
        DB::statement("CREATE UNIQUE INDEX payment_open_renewal_unique ON association_payment_requests (renewal_membership_id) WHERE renewal_membership_id IS NOT NULL AND status IN ('requested', 'submitted')");
        DB::statement("ALTER TABLE association_payment_requests ADD CONSTRAINT payment_renewal_shape CHECK ((renewal_membership_id IS NULL AND renewal_from IS NULL) OR (renewal_membership_id IS NOT NULL AND renewal_from IS NOT NULL AND membership_type = 'yearly'))");
        Schema::table('association_payment_receipts', fn (Blueprint $table) => $table->dropUnique(['membership_id']));
        Schema::create('association_membership_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('membership_id')->constrained('association_memberships')->restrictOnDelete();
            $table->foreignUuid('payment_id')->unique()->constrained('association_payment_requests')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['membership_id', 'created_at']);
        });
        // Initial membership IDs are UUIDs and make stable, collision-free term IDs.
        DB::statement('INSERT INTO association_membership_terms (id, membership_id, payment_id, starts_at, ends_at, created_at) SELECT id, id, payment_id, starts_at, ends_at, created_at FROM association_memberships');
    }

    public function down(): void
    {
        // A renewal introduces multiple payments and receipts per membership. Never discard that history on rollback.
        throw new RuntimeException('This data-preserving renewal migration is forward-only. Restore a verified database backup to revert it.');
    }
};
