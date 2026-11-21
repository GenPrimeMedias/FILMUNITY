<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE association_vendor_tds_reviews (
 id uuid PRIMARY KEY, association_id uuid NOT NULL, vendor_id uuid NOT NULL,
 effective_from date NOT NULL, reviewed_by bigint NOT NULL REFERENCES users(id),
 review_notes text NOT NULL CHECK(length(trim(review_notes))>0), supporting_reference varchar(500),
 legal_reference varchar(100) NOT NULL, reviewed_rate numeric(5,2) NOT NULL CHECK(reviewed_rate BETWEEN 0 AND 40),
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(association_id,vendor_id,effective_from),
 FOREIGN KEY(association_id,vendor_id) REFERENCES association_finance_vendors(association_id,id)
);
CREATE FUNCTION filmunity_vendor_tds_review_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Vendor TDS reviews are retained; add a new effective-dated review'; END $$;
CREATE TRIGGER vendor_tds_review_immutable BEFORE UPDATE OR DELETE ON association_vendor_tds_reviews
 FOR EACH ROW EXECUTE FUNCTION filmunity_vendor_tds_review_immutable();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain vendor review history.');
    }
};
