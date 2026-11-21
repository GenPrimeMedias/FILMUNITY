<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  DB::unprepared(<<<'SQL'
ALTER TABLE association_finance_vouchers DROP CONSTRAINT finance_voucher_kind,
 ADD CONSTRAINT finance_voucher_kind CHECK(kind IN ('receipt','payment','transfer','journal','opening','membership','reversal','invoice','bill')),
 ADD CONSTRAINT finance_voucher_tenant_id UNIQUE(association_id,id);
ALTER TABLE association_voucher_counters DROP CONSTRAINT association_voucher_counters_kind_check,
 ADD CONSTRAINT association_voucher_counters_kind_check CHECK(kind IN ('receipt','payment','transfer','journal','opening','membership','reversal','invoice','bill'));
ALTER TABLE association_finance_accounts ADD CONSTRAINT finance_account_tenant_id UNIQUE(association_id,id);
CREATE TABLE association_bill_documents (
 voucher_id uuid PRIMARY KEY, association_id uuid NOT NULL REFERENCES association_finance_settings(association_id) ON DELETE RESTRICT, party_account_id uuid NOT NULL,
 flow varchar(10) NOT NULL CHECK(flow IN ('invoice','bill','receipt','payment')),
 due_date date, client_token uuid NOT NULL, bridge_source_id uuid REFERENCES association_finance_vouchers(id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(association_id,voucher_id), UNIQUE(association_id,client_token),
 FOREIGN KEY(association_id,voucher_id) REFERENCES association_finance_vouchers(association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(association_id,party_account_id) REFERENCES association_finance_accounts(association_id,id) ON DELETE RESTRICT,
 CHECK((flow IN ('invoice','bill') AND due_date IS NOT NULL) OR (flow IN ('receipt','payment') AND due_date IS NULL)),
 CHECK(bridge_source_id IS NULL OR flow='receipt')
);
CREATE TABLE association_bill_allocations (
 id uuid PRIMARY KEY, association_id uuid NOT NULL, settlement_id uuid NOT NULL, document_id uuid NOT NULL,
 amount numeric(15,2) NOT NULL CHECK(amount>0), allocation_date date NOT NULL, note text NOT NULL CHECK(length(trim(note))>0),
 client_token uuid NOT NULL, created_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 voided_at timestamptz, voided_by bigint REFERENCES users(id) ON DELETE RESTRICT, void_note text,
 UNIQUE(association_id,client_token),
 FOREIGN KEY(association_id,settlement_id) REFERENCES association_bill_documents(association_id,voucher_id) ON DELETE RESTRICT,
 FOREIGN KEY(association_id,document_id) REFERENCES association_bill_documents(association_id,voucher_id) ON DELETE RESTRICT,
 CHECK(settlement_id<>document_id),
 CHECK((voided_at IS NULL AND voided_by IS NULL AND void_note IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_note IS NOT NULL AND length(trim(void_note))>0))
);
CREATE INDEX bill_document_bridge_source ON association_bill_documents(bridge_source_id) WHERE bridge_source_id IS NOT NULL;
CREATE INDEX bill_documents_party ON association_bill_documents(association_id,party_account_id,flow);
CREATE INDEX bill_allocations_source ON association_bill_allocations(settlement_id) WHERE voided_at IS NULL;
CREATE INDEX bill_allocations_target ON association_bill_allocations(document_id) WHERE voided_at IS NULL;
CREATE OR REPLACE FUNCTION filmunity_bill_amount(v_id uuid) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT (COALESCE(SUM(CASE WHEN d.flow IN ('invoice','payment') THEN l.debit_minor-l.credit_minor ELSE l.credit_minor-l.debit_minor END),0)::numeric/100)::numeric(15,2)
 FROM association_bill_documents d JOIN association_finance_lines l ON l.voucher_id=d.voucher_id AND l.account_id=d.party_account_id WHERE d.voucher_id=v_id
$$;
CREATE OR REPLACE FUNCTION filmunity_bill_document_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v association_finance_vouchers; p association_finance_accounts;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Bill references must be retained'; END IF;
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 SELECT * INTO v FROM association_finance_vouchers WHERE id=NEW.voucher_id;
 SELECT * INTO p FROM association_finance_accounts WHERE id=NEW.party_account_id;
 IF v.status<>'draft' THEN RAISE EXCEPTION 'Only draft references can be created or edited'; END IF;
 IF v.association_id<>NEW.association_id OR p.association_id<>NEW.association_id THEN RAISE EXCEPTION 'Foreign bill reference'; END IF;
 IF (NEW.flow IN ('invoice','receipt') AND p.membership_id IS NULL) OR (NEW.flow IN ('bill','payment') AND p.vendor_id IS NULL) THEN RAISE EXCEPTION 'Choose the matching member/vendor ledger'; END IF;
 IF NEW.bridge_source_id IS NOT NULL THEN
  IF NOT EXISTS(SELECT 1 FROM association_finance_vouchers WHERE id=NEW.bridge_source_id AND association_id=NEW.association_id AND status='posted' AND source_type='membership_receipt') THEN RAISE EXCEPTION 'Use a posted membership receipt from this association'; END IF;
  IF EXISTS(SELECT 1 FROM association_bill_documents prior_doc JOIN association_finance_vouchers ov ON ov.id=prior_doc.voucher_id WHERE prior_doc.bridge_source_id=NEW.bridge_source_id AND prior_doc.voucher_id<>NEW.voucher_id AND ov.status IN ('draft','submitted','posted')) THEN RAISE EXCEPTION 'Membership receipt already has an active reclassification'; END IF;
 END IF;
 IF NEW.flow<>v.kind AND NOT(NEW.flow='receipt' AND v.kind='journal' AND NEW.bridge_source_id IS NOT NULL) THEN RAISE EXCEPTION 'Voucher kind and reference disagree'; END IF;
 IF NEW.due_date<v.entry_date THEN RAISE EXCEPTION 'Due date cannot precede voucher date'; END IF;
 IF TG_OP='UPDATE' AND (to_jsonb(OLD)-'due_date')<>(to_jsonb(NEW)-'due_date') THEN RAISE EXCEPTION 'Bill party and identity are permanent'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER bill_document_guard BEFORE INSERT OR UPDATE OR DELETE ON association_bill_documents FOR EACH ROW EXECUTE FUNCTION filmunity_bill_document_guard();
CREATE OR REPLACE FUNCTION filmunity_bill_post_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d association_bill_documents; s association_finance_settings; n integer; wrong integer; source association_finance_vouchers; src_account uuid; src_amount numeric; party_member uuid;
BEGIN
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 IF NEW.status='reversed' AND OLD.status='posted' THEN
  IF EXISTS(SELECT 1 FROM association_bill_allocations WHERE voided_at IS NULL AND (settlement_id=NEW.id OR document_id=NEW.id)) THEN RAISE EXCEPTION 'Release active bill allocations before reversing'; END IF;
  IF EXISTS(SELECT 1 FROM association_bill_documents d JOIN association_finance_vouchers v ON v.id=d.voucher_id WHERE d.bridge_source_id=NEW.id AND v.status='posted') THEN RAISE EXCEPTION 'Reverse the membership reclassification before its source receipt'; END IF;
 END IF;
 IF NEW.status<>'posted' OR OLD.status='posted' THEN RETURN NEW; END IF;
 SELECT * INTO d FROM association_bill_documents WHERE voucher_id=NEW.id;
 IF d.voucher_id IS NULL THEN
  IF NEW.kind IN ('invoice','bill') THEN RAISE EXCEPTION 'Invoice/bill requires a party reference'; END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO s FROM association_finance_settings WHERE association_id=NEW.association_id;
 IF d.due_date<NEW.entry_date THEN RAISE EXCEPTION 'Due date cannot precede book date'; END IF;
 IF NEW.entry_date<s.starts_on OR NEW.entry_date<=s.locked_through THEN RAISE EXCEPTION 'Bill date is outside open books'; END IF;
 SELECT count(*),count(*) FILTER(WHERE CASE WHEN d.flow IN ('invoice','payment') THEN credit_minor>0 ELSE debit_minor>0 END) INTO n,wrong FROM association_finance_lines WHERE voucher_id=NEW.id AND account_id=d.party_account_id;
 IF n<>1 OR wrong<>0 OR filmunity_bill_amount(NEW.id)<=0 THEN RAISE EXCEPTION 'Party side must contain exactly one positive matching entry'; END IF;
 IF EXISTS(SELECT 1 FROM association_finance_lines l JOIN association_finance_accounts a ON a.id=l.account_id WHERE l.voucher_id=NEW.id AND l.account_id<>d.party_account_id AND (a.membership_id IS NOT NULL OR a.vendor_id IS NOT NULL)) THEN RAISE EXCEPTION 'Use one party per bill reference'; END IF;
 IF NEW.kind IN ('invoice','bill') AND EXISTS(SELECT 1 FROM association_finance_lines l JOIN association_finance_accounts a ON a.id=l.account_id WHERE l.voucher_id=NEW.id AND a.is_money) THEN RAISE EXCEPTION 'Invoices and bills do not move cash'; END IF;
 IF d.bridge_source_id IS NOT NULL THEN
  SELECT * INTO source FROM association_finance_vouchers WHERE id=d.bridge_source_id;
  IF source.status<>'posted' OR source.association_id<>NEW.association_id OR source.source_type<>'membership_receipt' OR NEW.entry_date<source.entry_date THEN RAISE EXCEPTION 'Reclassification needs an unreversed posted membership receipt'; END IF;
  SELECT a.id,l.credit_minor::numeric/100 INTO src_account,src_amount FROM association_finance_lines l JOIN association_finance_accounts a ON a.id=l.account_id WHERE l.voucher_id=source.id AND l.credit_minor>0;
  SELECT a.membership_id INTO party_member FROM association_finance_accounts a WHERE a.id=d.party_account_id;
  IF (SELECT count(*) FROM association_finance_lines WHERE voucher_id=source.id)<>2 OR NOT EXISTS(SELECT 1 FROM association_payment_receipts WHERE id=source.source_id AND membership_id=party_member AND association_id=NEW.association_id) THEN RAISE EXCEPTION 'Membership receipt does not match this member'; END IF;
  IF (SELECT count(*) FROM association_finance_lines WHERE voucher_id=NEW.id)<>2 OR filmunity_bill_amount(NEW.id)<>src_amount OR NOT EXISTS(SELECT 1 FROM association_finance_lines WHERE voucher_id=NEW.id AND account_id=src_account AND debit_minor=src_amount*100) THEN RAISE EXCEPTION 'Reclassification must offset the original credit exactly'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER bill_post_guard BEFORE UPDATE ON association_finance_vouchers FOR EACH ROW EXECUTE FUNCTION filmunity_bill_post_guard();
CREATE OR REPLACE FUNCTION filmunity_bill_allocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source association_bill_documents; target association_bill_documents; sv association_finance_vouchers; tv association_finance_vouchers; s association_finance_settings; used numeric;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Allocation history must be retained'; END IF;
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 SELECT * INTO s FROM association_finance_settings WHERE association_id=NEW.association_id;
 IF NEW.allocation_date<s.starts_on OR NEW.allocation_date<=s.locked_through THEN RAISE EXCEPTION 'Allocation date is outside open books'; END IF;
 IF TG_OP='UPDATE' THEN
  IF OLD.voided_at IS NOT NULL OR NEW.voided_at IS NULL OR (to_jsonb(OLD)-'voided_at'-'voided_by'-'void_note')<>(to_jsonb(NEW)-'voided_at'-'voided_by'-'void_note') THEN RAISE EXCEPTION 'Allocations are immutable; release once with a reason'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.voided_at IS NOT NULL THEN RAISE EXCEPTION 'New allocations cannot start released'; END IF;
 SELECT * INTO source FROM association_bill_documents WHERE voucher_id=NEW.settlement_id;
 SELECT * INTO target FROM association_bill_documents WHERE voucher_id=NEW.document_id;
 SELECT * INTO sv FROM association_finance_vouchers WHERE id=NEW.settlement_id;
 SELECT * INTO tv FROM association_finance_vouchers WHERE id=NEW.document_id;
 IF source.association_id<>NEW.association_id OR target.association_id<>NEW.association_id OR source.party_account_id<>target.party_account_id OR NOT((source.flow='receipt' AND target.flow='invoice') OR(source.flow='payment' AND target.flow='bill')) THEN RAISE EXCEPTION 'Settlement and bill must match tenant, party and direction'; END IF;
 IF sv.status<>'posted' OR tv.status<>'posted' OR NEW.allocation_date<sv.entry_date OR NEW.allocation_date<tv.entry_date THEN RAISE EXCEPTION 'Allocate posted documents on or after both book dates'; END IF;
 SELECT COALESCE(sum(amount),0) INTO used FROM association_bill_allocations WHERE settlement_id=NEW.settlement_id AND voided_at IS NULL;
 IF used+NEW.amount>filmunity_bill_amount(NEW.settlement_id) THEN RAISE EXCEPTION 'Allocation exceeds available settlement'; END IF;
 SELECT COALESCE(sum(amount),0) INTO used FROM association_bill_allocations WHERE document_id=NEW.document_id AND voided_at IS NULL;
 IF used+NEW.amount>filmunity_bill_amount(NEW.document_id) THEN RAISE EXCEPTION 'Allocation exceeds outstanding bill'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER bill_allocation_guard BEFORE INSERT OR UPDATE OR DELETE ON association_bill_allocations FOR EACH ROW EXECUTE FUNCTION filmunity_bill_allocation_guard();
SQL);
 }
 public function down(): void { throw new RuntimeException('Bill and allocation history must be retained. Use a coordinated backup or a reviewed forward correction.'); }
};
