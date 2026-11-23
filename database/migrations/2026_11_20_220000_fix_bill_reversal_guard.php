<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION filmunity_bill_post_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d association_bill_documents; s association_finance_settings; n integer; wrong integer; source association_finance_vouchers; src_account uuid; src_amount numeric; party_member uuid;
BEGIN
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 IF NEW.status='reversed' AND OLD.status='posted' THEN
  IF EXISTS(SELECT 1 FROM association_bill_allocations WHERE voided_at IS NULL AND (settlement_id=NEW.id OR document_id=NEW.id)) THEN RAISE EXCEPTION 'Release active bill allocations before reversing'; END IF;
  IF EXISTS(SELECT 1 FROM association_bill_documents bridge_doc JOIN association_finance_vouchers bridge_voucher ON bridge_voucher.id=bridge_doc.voucher_id WHERE bridge_doc.bridge_source_id=NEW.id AND bridge_voucher.status='posted') THEN RAISE EXCEPTION 'Reverse the membership reclassification before its source receipt'; END IF;
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
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Do not restore the broken reversal guard. Use a reviewed forward correction.');
    }
};
