<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE association_tally_ledger_mappings (
 association_id uuid NOT NULL, account_id uuid NOT NULL,
 ledger_name varchar(120) NOT NULL, name_key varchar(120) NOT NULL, parent_group varchar(80) NOT NULL,
 verified_by bigint NOT NULL REFERENCES users(id), verified_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(association_id,account_id), UNIQUE(association_id,name_key),
 FOREIGN KEY(association_id,account_id) REFERENCES association_finance_accounts(association_id,id)
);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain verified export mappings.');
    }
};
