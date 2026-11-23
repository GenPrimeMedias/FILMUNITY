<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE association_invoice_templates (
 id uuid PRIMARY KEY, association_id uuid NOT NULL REFERENCES association_finance_settings(association_id),
 party_account_id uuid NOT NULL, name varchar(160) NOT NULL, items jsonb NOT NULL,
 starts_on date NOT NULL, anchor_day integer NOT NULL CHECK(anchor_day BETWEEN 1 AND 31),
 next_period date NOT NULL CHECK(extract(day FROM next_period)=1), due_days integer NOT NULL CHECK(due_days BETWEEN 0 AND 90),
 paused boolean NOT NULL DEFAULT false, created_by bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(association_id,id), FOREIGN KEY(association_id,party_account_id) REFERENCES association_finance_accounts(association_id,id)
);
CREATE TABLE association_invoice_template_runs (
 id uuid PRIMARY KEY, association_id uuid NOT NULL, template_id uuid NOT NULL, period date NOT NULL CHECK(extract(day FROM period)=1),
 voucher_id uuid NOT NULL, snapshot jsonb NOT NULL, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(template_id,period), UNIQUE(voucher_id),
 FOREIGN KEY(association_id,template_id) REFERENCES association_invoice_templates(association_id,id),
 FOREIGN KEY(association_id,voucher_id) REFERENCES association_finance_vouchers(association_id,id)
);
CREATE FUNCTION filmunity_invoice_run_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Generated invoice run history must be retained'; END $$;
CREATE TRIGGER invoice_run_immutable BEFORE UPDATE OR DELETE ON association_invoice_template_runs
 FOR EACH ROW EXECUTE FUNCTION filmunity_invoice_run_immutable();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain recurring invoice history.');
    }
};
