<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_membership_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('statement');
            $table->string('status', 24)->default('pending');
            $table->text('decision_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['association_id', 'created_at'], 'membership_association_created_index');
            $table->index(['member_id', 'created_at'], 'membership_member_created_index');
        });

        DB::statement("CREATE UNIQUE INDEX membership_one_open_per_association
            ON association_membership_applications (association_id, member_id)
            WHERE status <> 'declined'");
    }

    public function down(): void
    {
        Schema::dropIfExists('association_membership_applications');
    }
};
