<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_workspace_settings', function (Blueprint $table): void {
            $table->uuid('association_id')->primary();
            $table->foreign('association_id', 'workspace_settings_association_fk')
                ->references('id')->on('association_registration_applications')->cascadeOnDelete();
            $table->string('display_name', 150);
            $table->text('description')->nullable();
            $table->string('accent', 7);
            $table->text('logo')->nullable();
            $table->json('fields');
            $table->unsignedInteger('version');
        });
        Schema::table('association_membership_applications', function (Blueprint $table): void {
            $table->json('form_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('association_membership_applications', function (Blueprint $table): void {
            $table->dropColumn('form_snapshot');
        });
        Schema::dropIfExists('association_workspace_settings');
    }
};
