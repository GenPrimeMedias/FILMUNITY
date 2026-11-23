<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_workspace_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('yearly_fee_minor')->nullable();
            $table->unsignedBigInteger('lifetime_fee_minor')->nullable();
        });
        Schema::table('association_membership_applications', function (Blueprint $table): void {
            $table->string('membership_type', 12)->nullable();
            $table->unsignedBigInteger('fee_minor')->nullable();
            $table->string('currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('association_membership_applications', function (Blueprint $table): void {
            $table->dropColumn(['membership_type', 'fee_minor', 'currency']);
        });
        Schema::table('association_workspace_settings', function (Blueprint $table): void {
            $table->dropColumn(['yearly_fee_minor', 'lifetime_fee_minor']);
        });
    }
};
