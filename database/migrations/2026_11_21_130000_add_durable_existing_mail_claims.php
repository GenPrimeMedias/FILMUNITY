<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['association_payment_notifications', 'membership_correspondence', 'association_membership_reminders'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->uuid('delivery_token')->nullable();
                if ($name !== 'association_payment_notifications') {
                    $table->timestampTz('attempted_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Interrupted delivery claims are retained; use a reviewed forward recovery.');
    }
};
