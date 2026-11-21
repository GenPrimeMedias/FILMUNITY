<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  DB::unprepared(<<<'SQL'
ALTER TABLE association_finance_vouchers DROP CONSTRAINT finance_voucher_kind,
 ADD CONSTRAINT finance_voucher_kind CHECK(kind IN ('receipt','payment','transfer','journal','opening','membership','reversal','invoice','bill','credit_note','debit_note'));
ALTER TABLE association_voucher_counters DROP CONSTRAINT association_voucher_counters_kind_check,
 ADD CONSTRAINT association_voucher_counters_kind_check CHECK(kind IN ('receipt','payment','transfer','journal','opening','membership','reversal','invoice','bill','credit_note','debit_note'));
CREATE TABLE association_finance_notes (
 voucher_id uuid PRIMARY KEY, association_id uuid NOT NULL REFERENCES association_finance_settings(association_id) ON DELETE RESTRICT,
 original_document_id uuid NOT NULL, reason text NOT NULL CHECK(length(trim(reason))>0),
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(association_id,voucher_id) REFERENCES association_finance_vouchers(association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(association_id,original_document_id) REFERENCES association_bill_documents(association_id,voucher_id) ON DELETE RESTRICT,
 CHECK(voucher_id<>original_document_id)
);
CREATE INDEX finance_notes_original ON association_finance_notes(association_id,original_document_id);
CREATE OR REPLACE FUNCTION filmunity_note_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE note_voucher association_finance_vouchers; original_voucher association_finance_vouchers; original_ref association_bill_documents;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Correction note history must be retained'; END IF;
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 SELECT * INTO note_voucher FROM association_finance_vouchers WHERE id=NEW.voucher_id;
 SELECT * INTO original_voucher FROM association_finance_vouchers WHERE id=NEW.original_document_id;
 SELECT * INTO original_ref FROM association_bill_documents WHERE voucher_id=NEW.original_document_id;
 IF note_voucher.status<>'draft' OR note_voucher.association_id<>NEW.association_id OR original_voucher.association_id<>NEW.association_id OR original_voucher.status<>'posted' THEN RAISE EXCEPTION 'Correction needs a draft note and a posted original from the same association'; END IF;
 IF NOT ((note_voucher.kind='credit_note' AND original_ref.flow='invoice') OR (note_voucher.kind='debit_note' AND original_ref.flow='bill')) THEN RAISE EXCEPTION 'Credit notes correct member invoices; debit notes correct vendor bills'; END IF;
 IF TG_OP='UPDATE' AND (to_jsonb(OLD)-'reason')<>(to_jsonb(NEW)-'reason') THEN RAISE EXCEPTION 'Correction note origin and identity are permanent'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_note_guard BEFORE INSERT OR UPDATE OR DELETE ON association_finance_notes FOR EACH ROW EXECUTE FUNCTION filmunity_note_guard();
CREATE OR REPLACE FUNCTION filmunity_validate_note(note_id uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE note_ref association_finance_notes; note_bill association_bill_documents; note_voucher association_finance_vouchers; original_ref association_bill_documents; original_voucher association_finance_vouchers;
 original_counter uuid; amount numeric; prior_amount numeric; original_amount numeric;
BEGIN
 SELECT * INTO note_ref FROM association_finance_notes WHERE voucher_id=note_id;
 SELECT * INTO note_bill FROM association_bill_documents WHERE voucher_id=note_id;
 SELECT * INTO note_voucher FROM association_finance_vouchers WHERE id=note_id;
 SELECT * INTO original_ref FROM association_bill_documents WHERE voucher_id=note_ref.original_document_id;
 SELECT * INTO original_voucher FROM association_finance_vouchers WHERE id=note_ref.original_document_id;
 IF note_ref.voucher_id IS NULL OR note_bill.voucher_id IS NULL OR original_voucher.status<>'posted' THEN RAISE EXCEPTION 'Note requires its registered reference and unreversed posted original'; END IF;
 IF note_ref.association_id<>note_voucher.association_id OR original_ref.association_id<>note_voucher.association_id OR note_bill.party_account_id<>original_ref.party_account_id THEN RAISE EXCEPTION 'Correction must use the same association and party as its original'; END IF;
 IF NOT ((note_voucher.kind='credit_note' AND original_ref.flow='invoice' AND note_bill.flow='receipt') OR (note_voucher.kind='debit_note' AND original_ref.flow='bill' AND note_bill.flow='payment')) THEN RAISE EXCEPTION 'Correction direction does not match the original'; END IF;
 IF note_voucher.entry_date<original_voucher.entry_date THEN RAISE EXCEPTION 'Correction cannot predate its original'; END IF;
 SELECT account_id INTO original_counter FROM association_finance_lines WHERE voucher_id=original_ref.voucher_id AND account_id<>original_ref.party_account_id;
 IF (SELECT count(*) FROM association_finance_lines WHERE voucher_id=original_ref.voucher_id)<>2 OR (SELECT count(*) FROM association_finance_lines WHERE voucher_id=note_id)<>2 THEN RAISE EXCEPTION 'Correction supports a single counter account on each document'; END IF;
 IF EXISTS(SELECT 1 FROM association_finance_lines note_line JOIN association_finance_accounts note_account ON note_account.id=note_line.account_id WHERE note_line.voucher_id=note_id AND (note_account.is_money OR (note_line.account_id<>original_ref.party_account_id AND note_line.account_id<>original_counter))) THEN RAISE EXCEPTION 'Correction must offset the original accounts without moving cash'; END IF;
 amount:=filmunity_bill_amount(note_id); original_amount:=filmunity_bill_amount(original_ref.voucher_id);
 IF amount<=0 OR NOT EXISTS(SELECT 1 FROM association_finance_lines WHERE voucher_id=note_id AND account_id=original_counter AND CASE WHEN note_voucher.kind='credit_note' THEN debit_minor=amount*100 ELSE credit_minor=amount*100 END) THEN RAISE EXCEPTION 'Correction entry side or amount is invalid'; END IF;
 SELECT COALESCE(sum(filmunity_bill_amount(existing_note.voucher_id)),0) INTO prior_amount FROM association_finance_notes existing_note JOIN association_finance_vouchers existing_voucher ON existing_voucher.id=existing_note.voucher_id WHERE existing_note.original_document_id=original_ref.voucher_id AND existing_note.voucher_id<>note_id AND existing_voucher.status='posted';
 IF amount+prior_amount>original_amount THEN RAISE EXCEPTION 'Total posted corrections exceed the original document amount'; END IF;
END $$;
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
 IF NEW.flow<>v.kind AND NOT ((v.kind='credit_note' AND NEW.flow='receipt' OR v.kind='debit_note' AND NEW.flow='payment') AND EXISTS(SELECT 1 FROM association_finance_notes note_ref WHERE note_ref.voucher_id=NEW.voucher_id)) AND NOT(NEW.flow='receipt' AND v.kind='journal' AND NEW.bridge_source_id IS NOT NULL) THEN RAISE EXCEPTION 'Voucher kind and reference disagree'; END IF;
 IF NEW.due_date<v.entry_date THEN RAISE EXCEPTION 'Due date cannot precede voucher date'; END IF;
 IF TG_OP='UPDATE' AND (to_jsonb(OLD)-'due_date')<>(to_jsonb(NEW)-'due_date') THEN RAISE EXCEPTION 'Bill party and identity are permanent'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION filmunity_bill_post_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d association_bill_documents; s association_finance_settings; n integer; wrong integer; source association_finance_vouchers; src_account uuid; src_amount numeric; party_member uuid;
BEGIN
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 IF NEW.status='reversed' AND OLD.status='posted' THEN
  IF EXISTS(SELECT 1 FROM association_finance_notes child_note JOIN association_finance_vouchers child_voucher ON child_voucher.id=child_note.voucher_id WHERE child_note.original_document_id=NEW.id AND child_voucher.status='posted') THEN RAISE EXCEPTION 'Reverse posted correction notes before their original document'; END IF;
  IF EXISTS(SELECT 1 FROM association_bill_allocations WHERE voided_at IS NULL AND (settlement_id=NEW.id OR document_id=NEW.id)) THEN RAISE EXCEPTION 'Release active bill allocations before reversing'; END IF;
  IF EXISTS(SELECT 1 FROM association_bill_documents bridge_doc JOIN association_finance_vouchers bridge_voucher ON bridge_voucher.id=bridge_doc.voucher_id WHERE bridge_doc.bridge_source_id=NEW.id AND bridge_voucher.status='posted') THEN RAISE EXCEPTION 'Reverse the membership reclassification before its source receipt'; END IF;
 END IF;
 IF NEW.status<>'posted' OR OLD.status='posted' THEN RETURN NEW; END IF;
 IF NEW.kind IN ('credit_note','debit_note') THEN PERFORM filmunity_validate_note(NEW.id); END IF;
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
SQL);
 }
 public function down(): void { throw new RuntimeException('Correction note history must be retained. Use a coordinated backup or reviewed forward correction.'); }
};
