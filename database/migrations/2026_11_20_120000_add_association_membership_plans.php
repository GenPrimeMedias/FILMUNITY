<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_membership_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->string('default_code', 12)->nullable();
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->string('term', 12);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['association_id', 'default_code'], 'association_plan_default_unique');
            $table->unique(['association_id', 'name_key'], 'association_plan_name_unique');
        });
        foreach (['association_membership_applications', 'association_payment_requests', 'association_memberships'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignUuid('plan_id')->nullable()->constrained('association_membership_plans')->restrictOnDelete();
                $table->json('plan_snapshot')->nullable();
            });
        }
        DB::statement("INSERT INTO association_membership_plans (id, association_id, default_code, name, name_key, term, amount_minor, active, created_at, updated_at)
            SELECT gen_random_uuid(), association_id, 'yearly', 'Yearly', 'yearly', 'yearly', yearly_fee_minor, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM association_workspace_settings WHERE yearly_fee_minor IS NOT NULL");
        DB::statement("INSERT INTO association_membership_plans (id, association_id, default_code, name, name_key, term, amount_minor, active, created_at, updated_at)
            SELECT gen_random_uuid(), association_id, 'lifetime', 'Life-Time', 'life-time', 'lifetime', lifetime_fee_minor, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM association_workspace_settings WHERE lifetime_fee_minor IS NOT NULL");
    }

    public function down(): void
    {
        throw new RuntimeException('Plan selections and financial snapshots are retained for audit. Restore a verified backup to revert.');
    }
};
