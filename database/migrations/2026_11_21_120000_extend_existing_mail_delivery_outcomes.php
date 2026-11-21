<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['association_payment_notifications', 'membership_correspondence', 'association_membership_reminders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('delivery_state', 20)->default('pending');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Delivery review outcomes are retained; use reviewed forward recovery.');
    }
};
