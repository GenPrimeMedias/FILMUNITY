<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE association_invoice_reminders (
 id uuid PRIMARY KEY, association_id uuid NOT NULL, voucher_id uuid NOT NULL,
 recipient varchar(254) NOT NULL, member_id bigint NOT NULL REFERENCES users(id), reminder_on date NOT NULL,
 requested_by bigint NOT NULL REFERENCES users(id), snapshot jsonb NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','disabled','simulated','cancelled','failed')),
 attempts integer NOT NULL DEFAULT 0 CHECK(attempts>=0), last_error text, attempted_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(voucher_id,recipient,reminder_on),
 FOREIGN KEY(association_id,voucher_id) REFERENCES association_finance_vouchers(association_id,id)
);
CREATE INDEX invoice_reminders_tenant ON association_invoice_reminders(association_id,created_at);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain reminder delivery history.');
    }
};
