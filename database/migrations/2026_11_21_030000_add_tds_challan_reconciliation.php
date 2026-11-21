<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE association_tds_challans (
 id uuid PRIMARY KEY, association_id uuid NOT NULL REFERENCES association_finance_settings(association_id),
 remittance_line_id bigint NOT NULL UNIQUE REFERENCES association_finance_lines(id),
 reference varchar(160) NOT NULL, paid_on date NOT NULL, amount_minor bigint NOT NULL CHECK(amount_minor>0),
 client_token uuid NOT NULL, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(association_id,client_token), UNIQUE(association_id,reference), UNIQUE(association_id,id)
);
CREATE TABLE association_tds_matches (
 id uuid PRIMARY KEY, association_id uuid NOT NULL, challan_id uuid NOT NULL,
 document_id uuid NOT NULL, amount_minor bigint NOT NULL CHECK(amount_minor>0),
 client_token uuid NOT NULL, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 released_at timestamptz, released_by bigint REFERENCES users(id), release_reason text,
 UNIQUE(association_id,client_token),
 FOREIGN KEY(association_id,challan_id) REFERENCES association_tds_challans(association_id,id),
 FOREIGN KEY(association_id,document_id) REFERENCES association_finance_vouchers(association_id,id),
 CHECK((released_at IS NULL AND released_by IS NULL AND release_reason IS NULL) OR
 (released_at IS NOT NULL AND released_by IS NOT NULL AND length(trim(release_reason))>0))
);
CREATE INDEX tds_match_document ON association_tds_matches(document_id) WHERE released_at IS NULL;
CREATE FUNCTION filmunity_tds_reconciliation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE tenant uuid;
BEGIN
 tenant:=NEW.association_id;
 PERFORM 1 FROM association_registration_applications WHERE id=tenant FOR UPDATE;
 IF EXISTS (
  SELECT 1 FROM association_tds_matches m
  JOIN association_tds_challans c ON c.id=m.challan_id
  JOIN association_finance_lines rem ON rem.id=c.remittance_line_id
  JOIN association_finance_vouchers rv ON rv.id=rem.voucher_id
  JOIN association_finance_vouchers bill ON bill.id=m.document_id
  WHERE m.association_id=tenant AND m.released_at IS NULL AND
   (rv.status<>'posted' OR bill.status<>'posted' OR
    (SELECT sum(x.amount_minor) FROM association_tds_matches x WHERE x.document_id=m.document_id AND x.released_at IS NULL) >
    coalesce((SELECT sum(l.credit_minor-l.debit_minor) FROM association_finance_lines l WHERE l.voucher_id=bill.id AND l.account_id=rem.account_id),0) -
    coalesce((SELECT sum(l.debit_minor-l.credit_minor) FROM association_finance_notes n
      JOIN association_finance_vouchers v ON v.id=n.voucher_id AND v.status='posted'
      JOIN association_finance_lines l ON l.voucher_id=v.id AND l.account_id=rem.account_id
      WHERE n.original_document_id=bill.id),0))
 ) THEN RAISE EXCEPTION 'Release TDS matches before reversing or reducing reconciled liabilities'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER tds_reconciliation_post_guard AFTER UPDATE OF status ON association_finance_vouchers
 FOR EACH ROW EXECUTE FUNCTION filmunity_tds_reconciliation_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain challan and reconciliation audit history.');
    }
};
