<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_registration_applications', function (Blueprint $table): void {
            $table->string('registration_number', 100)->nullable();
            $table->string('registering_authority', 160)->nullable();
            $table->string('state_or_region', 100)->nullable();
            $table->date('registered_on')->nullable();
            $table->string('certificate_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('association_registration_applications', function (Blueprint $table): void {
            $table->dropColumn(['registration_number', 'registering_authority', 'state_or_region', 'registered_on', 'certificate_path']);
        });
    }
};
