<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE association_finance_tax_settings (
 association_id uuid PRIMARY KEY REFERENCES association_finance_settings(association_id) ON DELETE RESTRICT,
 gst_registered boolean NOT NULL DEFAULT false, gstin varchar(15), pan varchar(10), tan varchar(10), state_code varchar(2) NOT NULL,
 legal_name varchar(160) NOT NULL, address text NOT NULL, effective_from date NOT NULL,
 tds_enabled boolean NOT NULL DEFAULT false, policy_note text NOT NULL, updated_by bigint NOT NULL REFERENCES users(id), updated_at timestamptz NOT NULL,
 CHECK(state_code ~ '^[0-9]{2}$'), CHECK(NOT gst_registered OR gstin IS NOT NULL),CHECK(NOT tds_enabled OR tan IS NOT NULL)
);
CREATE TABLE association_finance_tax_counters (
 association_id uuid NOT NULL REFERENCES association_finance_settings(association_id) ON DELETE RESTRICT, fy_start date NOT NULL, next_number bigint NOT NULL CHECK(next_number BETWEEN 1 AND 10000000),PRIMARY KEY(association_id,fy_start)
);
CREATE TABLE association_finance_tax_documents (
 voucher_id uuid PRIMARY KEY, association_id uuid NOT NULL, serial varchar(16) NOT NULL,
 taxable_base numeric(15,2) NOT NULL CHECK(taxable_base>=0), exempt_base numeric(15,2) NOT NULL CHECK(exempt_base>=0),
 gst_rate numeric(5,2) NOT NULL CHECK(gst_rate BETWEEN 0 AND 40), mode varchar(8) NOT NULL CHECK(mode IN ('none','cgst','utgst','igst')),
 cgst numeric(15,2) NOT NULL CHECK(cgst>=0), sgst numeric(15,2) NOT NULL CHECK(sgst>=0), igst numeric(15,2) NOT NULL CHECK(igst>=0),
 tds_base numeric(15,2) NOT NULL CHECK(tds_base>=0), tds_rate numeric(5,2) NOT NULL CHECK(tds_rate BETWEEN 0 AND 40), tds numeric(15,2) NOT NULL CHECK(tds>=0),
 gross numeric(15,2) NOT NULL, net numeric(15,2) NOT NULL CHECK(net>0), snapshot jsonb NOT NULL, expected_lines jsonb NOT NULL,created_at timestamptz NOT NULL,
 FOREIGN KEY(association_id,voucher_id) REFERENCES association_bill_documents(association_id,voucher_id) ON DELETE RESTRICT,
 UNIQUE(association_id,serial),CHECK(jsonb_typeof(snapshot)='object' AND snapshot ?& ARRAY['effective_from','entry_date','flow']), CHECK(jsonb_typeof(expected_lines)='array'),CHECK(taxable_base+exempt_base>0), CHECK(tds_base<=taxable_base+exempt_base),
 CHECK(gross=taxable_base+exempt_base+cgst+sgst+igst),CHECK(net=gross-tds),CHECK(tds=round(tds_base*tds_rate/100,2)),
 CHECK((mode='none' AND gst_rate=0 AND cgst=0 AND sgst=0 AND igst=0) OR (mode IN ('cgst','utgst') AND cgst=round(taxable_base*gst_rate/200,2) AND sgst=cgst AND igst=0) OR (mode='igst' AND cgst=0 AND sgst=0 AND igst=round(taxable_base*gst_rate/100,2)))
);
CREATE INDEX finance_tax_date_lookup ON association_finance_tax_documents(association_id,voucher_id);
CREATE FUNCTION filmunity_tax_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v association_finance_vouchers;
BEGIN
 IF TG_OP IN ('DELETE','UPDATE') THEN RAISE EXCEPTION 'Tax snapshots are retained; reject/reverse and reissue'; END IF;
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 SELECT * INTO v FROM association_finance_vouchers WHERE id=NEW.voucher_id;
 IF v.status<>'draft' OR v.kind NOT IN ('invoice','bill') THEN RAISE EXCEPTION 'Tax snapshot requires a draft invoice or bill'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_tax_history BEFORE INSERT OR UPDATE OR DELETE ON association_finance_tax_documents FOR EACH ROW EXECUTE FUNCTION filmunity_tax_history_guard();
CREATE FUNCTION filmunity_tax_note_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM association_finance_tax_documents WHERE voucher_id=NEW.original_document_id) THEN RAISE EXCEPTION 'Simple notes cannot correct a frozen tax document; use reviewed tax correction'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_tax_note BEFORE INSERT ON association_finance_notes FOR EACH ROW EXECUTE FUNCTION filmunity_tax_note_guard();
CREATE FUNCTION filmunity_tax_post_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t association_finance_tax_documents; d association_bill_documents; actual jsonb;
BEGIN
 IF NEW.status<>'posted' OR OLD.status='posted' THEN RETURN NEW; END IF;
 SELECT * INTO t FROM association_finance_tax_documents WHERE voucher_id=NEW.id;
 IF t.voucher_id IS NULL THEN RETURN NEW; END IF;
 SELECT * INTO d FROM association_bill_documents WHERE voucher_id=NEW.id;
 IF t.association_id<>NEW.association_id OR filmunity_bill_amount(NEW.id)<>t.net THEN RAISE EXCEPTION 'Tax document party total differs from its frozen calculation'; END IF;
 IF NEW.entry_date<>(t.snapshot->>'entry_date')::date OR NEW.kind<>(t.snapshot->>'flow') THEN RAISE EXCEPTION 'Tax date/type differs from the frozen snapshot'; END IF;
 IF NEW.entry_date<(t.snapshot->>'effective_from')::date OR (NEW.kind='invoice' AND t.tds<>0) THEN RAISE EXCEPTION 'Invalid tax effective date or member TDS'; END IF;
 SELECT jsonb_agg(jsonb_build_object('account_id',account_id,'debit_minor',debit_minor,'credit_minor',credit_minor) ORDER BY account_id) INTO actual FROM association_finance_lines WHERE voucher_id=NEW.id;
 IF actual IS DISTINCT FROM t.expected_lines THEN RAISE EXCEPTION 'Tax entry lines differ from the frozen tax calculation'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_tax_post BEFORE UPDATE ON association_finance_vouchers FOR EACH ROW EXECUTE FUNCTION filmunity_tax_post_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain tax history. Use reviewed forward changes or coordinated backup recovery.');
    }
};
