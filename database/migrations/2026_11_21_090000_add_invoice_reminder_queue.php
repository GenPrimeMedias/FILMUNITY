<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE association_invoice_reminders DROP CONSTRAINT association_invoice_reminders_status_check,
 ADD CONSTRAINT association_invoice_reminders_status_check CHECK(status IN ('pending','disabled','simulated','cancelled','failed','queued','sending','sent','skipped','uncertain')),
 ADD COLUMN queue_token uuid, ADD COLUMN queued_at timestamptz, ADD COLUMN completed_at timestamptz;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain reminder queue history.');
    }
};
