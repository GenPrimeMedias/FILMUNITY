<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE association_vendor_tds_reviews
 ADD COLUMN threshold_amount numeric(15,2) CHECK(threshold_amount>0),
 ADD COLUMN threshold_basis varchar(40) CHECK(threshold_basis='pre_gst_vendor_bills'),
 ADD COLUMN threshold_period varchar(30) CHECK(threshold_period='april_march'),
 ADD COLUMN threshold_accountant_reviewed boolean NOT NULL DEFAULT false,
 ADD COLUMN threshold_history_reviewed boolean NOT NULL DEFAULT false;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain reviewed threshold settings.');
    }
};
