<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_registration_applications', function (Blueprint $table): void {
            $table->string('official_contact_email', 254)->nullable();
            $table->text('registered_office_address')->nullable();
            $table->string('website', 2048)->nullable();
            $table->timestamp('approval_email_sent_at')->nullable();
            $table->index(['applicant_id', 'status'], 'association_applicant_status_index');
            $table->dropUnique(['applicant_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::table('association_registration_applications', function (Blueprint $table): void {
            $table->unique(['applicant_id', 'name_key']);
            $table->dropIndex('association_applicant_status_index');
            $table->dropColumn(['official_contact_email', 'registered_office_address', 'website', 'approval_email_sent_at']);
        });
    }
};
