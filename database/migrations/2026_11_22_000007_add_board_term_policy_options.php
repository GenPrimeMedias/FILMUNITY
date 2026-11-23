<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_governance_policies', function (Blueprint $table): void {
            $table->string('default_term_duration', 24)->nullable()->after('notice_days');
            $table->unsignedInteger('custom_term_months')->nullable()->after('default_term_duration');
            $table->unsignedInteger('max_consecutive_terms')->nullable()->after('custom_term_months');
        });
    }

    public function down(): void
    {
        Schema::table('association_governance_policies', function (Blueprint $table): void {
            $table->dropColumn(['default_term_duration', 'custom_term_months', 'max_consecutive_terms']);
        });
    }
};
