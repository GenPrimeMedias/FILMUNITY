<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_membership_numbering', function (Blueprint $table): void {
            $table->foreignUuid('association_id')->primary()->constrained('association_registration_applications')->restrictOnDelete();
            $table->string('prefix', 16)->default('MEM-');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedSmallInteger('padding')->default(6);
            $table->timestampTz('updated_at')->nullable();
        });
        Schema::create('association_membership_number_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->timestampTz('created_at');
            $table->index(['association_id', 'created_at']);
        });
        // Continue existing MEM- numbering without consulting receipt counts (renewals also issue receipts).
        DB::statement("INSERT INTO association_membership_numbering (association_id, prefix, next_number, padding, updated_at)
            SELECT association.id, 'MEM-', COALESCE(MAX(CASE WHEN membership.number ~ '^MEM-[0-9]{1,12}$'
                THEN substring(membership.number from 5)::bigint ELSE NULL END), 0) + 1, 6, CURRENT_TIMESTAMP
            FROM association_registration_applications AS association
            LEFT JOIN association_memberships AS membership ON membership.association_id = association.id
            GROUP BY association.id");
        DB::statement('ALTER TABLE association_membership_numbering ADD CONSTRAINT membership_numbering_limits CHECK (next_number >= 1 AND next_number <= 1000000000000 AND padding BETWEEN 0 AND 12)');
    }

    public function down(): void
    {
        throw new RuntimeException('Membership numbering preserves issued identifiers and its audit history. Revert using a verified database/source backup.');
    }
};
