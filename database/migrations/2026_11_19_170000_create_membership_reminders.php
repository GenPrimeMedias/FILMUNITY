<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_membership_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('membership_id')->constrained('association_memberships')->restrictOnDelete();
            $table->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->date('reminder_on');
            $table->string('recipient');
            $table->json('snapshot');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['membership_id', 'expires_at', 'reminder_on'], 'membership_reminder_day_unique');
            $table->index(['association_id', 'created_at']);
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Reminder delivery history is retained for audit. Use a verified backup to revert.');
    }
};
