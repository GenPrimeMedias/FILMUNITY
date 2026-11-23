<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE association_vendor_tds_reviews
 ADD COLUMN certificate_reference varchar(160),
 ADD COLUMN valid_from date,
 ADD COLUMN valid_until date,
 ADD COLUMN evidence_path text,
 ADD CONSTRAINT vendor_tds_certificate_dates CHECK (
  (certificate_reference IS NULL AND valid_from IS NULL AND valid_until IS NULL) OR
  (certificate_reference IS NOT NULL AND length(trim(certificate_reference))>0 AND valid_from IS NOT NULL AND valid_until IS NOT NULL AND valid_until>=valid_from)
 );
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain reviewed certificate history.');
    }
};
