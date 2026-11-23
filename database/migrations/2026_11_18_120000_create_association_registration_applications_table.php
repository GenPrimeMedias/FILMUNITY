<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_registration_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('name_key', 160);
            $table->string('organisation_type', 24);
            $table->string('industry', 24);
            $table->string('city', 100);
            $table->string('representative_role', 100);
            $table->string('status', 24)->default('pending');
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'name_key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association_registration_applications');
    }
};
