<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {DB::unprepared(<<<'SQL'
ALTER TABLE association_finance_tax_documents ADD COLUMN calculation_version smallint NOT NULL DEFAULT 1 CHECK(calculation_version IN (1,2));
ALTER TABLE association_finance_tax_documents ADD CONSTRAINT tax_documents_scoped_unique UNIQUE(association_id,voucher_id);
DO $$
DECLARE c record; n int:=0;
BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='association_finance_tax_documents'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%round%taxable_base%gst_rate%' LOOP
  EXECUTE format('ALTER TABLE association_finance_tax_documents DROP CONSTRAINT %I',c.conname);n:=n+1;
 END LOOP;
 IF n<>1 THEN RAISE EXCEPTION 'Expected the single-rate GST constraint; no migration changes committed'; END IF;
END $$;
ALTER TABLE association_finance_tax_documents ADD CONSTRAINT tax_document_gst_version CHECK(
 (calculation_version=1 AND ((mode='none' AND gst_rate=0 AND cgst=0 AND sgst=0 AND igst=0) OR (mode IN ('cgst','utgst') AND cgst=round(taxable_base*gst_rate/200,2) AND sgst=cgst AND igst=0) OR (mode='igst' AND cgst=0 AND sgst=0 AND igst=round(taxable_base*gst_rate/100,2))))
 OR (calculation_version=2 AND gst_rate=0 AND ((mode='none' AND cgst=0 AND sgst=0 AND igst=0) OR (mode IN ('cgst','utgst') AND cgst=sgst AND igst=0) OR (mode='igst' AND cgst=0 AND sgst=0)))
);
CREATE TABLE association_finance_tax_items (
 id bigserial PRIMARY KEY, association_id uuid NOT NULL, voucher_id uuid NOT NULL,line_no smallint NOT NULL CHECK(line_no BETWEEN 1 AND 20),
 counter_account_id uuid NOT NULL,description text NOT NULL CHECK(length(trim(description))>0),
 treatment varchar(10) NOT NULL CHECK(treatment IN ('taxable','exempt','non_gst')),exemption_reason text,hsn_sac varchar(8),
 amount numeric(15,2) NOT NULL CHECK(amount>0),gst_rate numeric(5,2) NOT NULL CHECK(gst_rate BETWEEN 0 AND 40),tds_base numeric(15,2) NOT NULL CHECK(tds_base BETWEEN 0 AND amount),
 cgst numeric(15,2) NOT NULL CHECK(cgst>=0),sgst numeric(15,2) NOT NULL CHECK(sgst>=0),igst numeric(15,2) NOT NULL CHECK(igst>=0),gross numeric(15,2) NOT NULL CHECK(gross=amount+cgst+sgst+igst),
 FOREIGN KEY(association_id,voucher_id) REFERENCES association_finance_tax_documents(association_id,voucher_id) ON DELETE RESTRICT,
 FOREIGN KEY(association_id,counter_account_id) REFERENCES association_finance_accounts(association_id,id) ON DELETE RESTRICT,
 UNIQUE(voucher_id,line_no),CHECK(treatment='taxable' OR (gst_rate=0 AND exemption_reason IS NOT NULL AND length(trim(exemption_reason))>0)),CHECK(gst_rate=0 OR hsn_sac IS NOT NULL AND hsn_sac ~ '^[0-9]{4,8}$')
);
CREATE INDEX finance_tax_items_account ON association_finance_tax_items(association_id,counter_account_id,voucher_id);
CREATE FUNCTION filmunity_tax_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t association_finance_tax_documents;v association_finance_vouchers;a association_finance_accounts;item jsonb;
BEGIN
 IF TG_OP IN ('UPDATE','DELETE') THEN RAISE EXCEPTION 'Charge snapshots are immutable; reject/reissue or use a reviewed correction'; END IF;
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 SELECT * INTO t FROM association_finance_tax_documents WHERE voucher_id=NEW.voucher_id;
 SELECT * INTO v FROM association_finance_vouchers WHERE id=NEW.voucher_id;
 SELECT * INTO a FROM association_finance_accounts WHERE id=NEW.counter_account_id;
 IF v.status<>'draft' OR t.calculation_version<>2 OR t.association_id<>NEW.association_id THEN RAISE EXCEPTION 'Charge lines require a matching multi-line draft'; END IF;
 IF a.is_money OR a.membership_id IS NOT NULL OR a.vendor_id IS NOT NULL OR NOT a.active OR a.code LIKE 'TAX-%' OR (v.kind='invoice' AND a.type NOT IN ('income','fund','liability')) OR (v.kind='bill' AND a.type NOT IN ('expense','asset')) THEN RAISE EXCEPTION 'Charge classification must be an active matching non-party account'; END IF;
 item:=t.snapshot->'items'->(NEW.line_no-1);
 IF item IS NULL OR (item->>'counter_account_id')::uuid IS DISTINCT FROM NEW.counter_account_id OR item->>'description' IS DISTINCT FROM NEW.description OR item->>'treatment' IS DISTINCT FROM NEW.treatment OR (item->>'amount')::numeric IS DISTINCT FROM NEW.amount OR (item->>'gst_rate')::numeric IS DISTINCT FROM NEW.gst_rate OR (item->>'tds_base')::numeric IS DISTINCT FROM NEW.tds_base OR coalesce(item->>'hsn_sac','')<>coalesce(NEW.hsn_sac,'') OR coalesce(item->>'exemption_reason','')<>coalesce(NEW.exemption_reason,'') THEN RAISE EXCEPTION 'Charge line differs from the frozen source snapshot'; END IF;
 IF (t.mode='none' AND (NEW.gst_rate<>0 OR NEW.cgst<>0 OR NEW.sgst<>0 OR NEW.igst<>0)) OR (t.mode IN ('cgst','utgst') AND (NEW.cgst<>round(NEW.amount*NEW.gst_rate/200,2) OR NEW.sgst<>NEW.cgst OR NEW.igst<>0)) OR (t.mode='igst' AND (NEW.cgst<>0 OR NEW.sgst<>0 OR NEW.igst<>round(NEW.amount*NEW.gst_rate/100,2))) THEN RAISE EXCEPTION 'Charge GST components do not match exact line rounding'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_tax_item_guard BEFORE INSERT OR UPDATE OR DELETE ON association_finance_tax_items FOR EACH ROW EXECUTE FUNCTION filmunity_tax_item_guard();
CREATE FUNCTION filmunity_tax_items_post_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t association_finance_tax_documents;total record;
BEGIN
 IF NEW.status<>'posted' OR OLD.status='posted' THEN RETURN NEW; END IF;
 SELECT * INTO t FROM association_finance_tax_documents WHERE voucher_id=NEW.id;
 IF t.voucher_id IS NULL OR t.calculation_version=1 THEN RETURN NEW; END IF;
 SELECT count(*) AS n,coalesce(sum(amount) FILTER(WHERE treatment='taxable'),0) AS taxable,coalesce(sum(amount) FILTER(WHERE treatment<>'taxable'),0) AS exempt,sum(cgst) AS cg,sum(sgst) AS sg,sum(igst) AS ig,sum(tds_base) AS tb,sum(gross) AS gross INTO total FROM association_finance_tax_items WHERE voucher_id=NEW.id;
 IF jsonb_typeof(t.snapshot->'items') IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Missing charge snapshot list'; END IF;
 IF total.n<1 OR total.n<>jsonb_array_length(t.snapshot->'items') OR total.taxable<>t.taxable_base OR total.exempt<>t.exempt_base OR total.cg<>t.cgst OR total.sg<>t.sgst OR total.ig<>t.igst OR total.tb<>t.tds_base OR total.gross<>t.gross THEN RAISE EXCEPTION 'Charge totals differ from the document header'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_tax_items_post BEFORE UPDATE ON association_finance_vouchers FOR EACH ROW EXECUTE FUNCTION filmunity_tax_items_post_guard();
SQL);}
 public function down():void {throw new RuntimeException('Retain multi-line accounting history; use a reviewed forward change or coordinated backup.');}
};
