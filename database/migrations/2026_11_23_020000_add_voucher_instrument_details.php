<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_finance_vouchers', function (Blueprint $table): void {
            $table->string('instrument_mode', 40)->nullable();
            $table->string('instrument_reference', 120)->nullable();
            $table->date('instrument_date')->nullable();
            $table->string('allocation_reference_type', 20)->nullable();
            $table->uuid('allocation_target_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('association_finance_vouchers', function (Blueprint $table): void {
            $table->dropColumn(['instrument_mode', 'instrument_reference', 'instrument_date']);
        });
    }
};
