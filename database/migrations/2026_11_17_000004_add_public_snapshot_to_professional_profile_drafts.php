<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_profile_drafts', function (Blueprint $table): void {
            $table->jsonb('public_profile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('professional_profile_drafts', function (Blueprint $table): void {
            $table->dropColumn('public_profile');
        });
    }
};
