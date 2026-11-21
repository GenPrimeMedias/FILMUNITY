<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE association_finance_notes ADD COLUMN tax_correction jsonb;

-- Legacy tax documents have one classification account and up to two frozen bases.
CREATE FUNCTION filmunity_tax_note_charges(original uuid)
RETURNS TABLE(line_no integer, account_id uuid, description text, treatment text,
              amount numeric, gst_rate numeric, cgst numeric, sgst numeric, igst numeric)
LANGUAGE sql STABLE AS $$
 SELECT i.line_no, i.counter_account_id, i.description, i.treatment, i.amount,
        i.gst_rate, i.cgst, i.sgst, i.igst
 FROM association_finance_tax_items i WHERE i.voucher_id=original
 UNION ALL
 SELECT x.line_no, (t.snapshot->>'counter_account_id')::uuid, x.description, x.treatment,
        x.amount, x.gst_rate, x.cgst, x.sgst, x.igst
 FROM association_finance_tax_documents t
 CROSS JOIN LATERAL (VALUES
   (1,'Original taxable charges','taxable',t.taxable_base,t.gst_rate,t.cgst,t.sgst,t.igst),
   (2,'Original exempt charges','exempt',t.exempt_base,0,0,0,0)
 ) x(line_no,description,treatment,amount,gst_rate,cgst,sgst,igst)
 WHERE t.voucher_id=original AND t.calculation_version=1 AND x.amount>0
$$;

CREATE FUNCTION filmunity_tax_note_used(original uuid, except_note uuid)
RETURNS TABLE(line_no integer, amount numeric, cgst numeric, sgst numeric, igst numeric)
LANGUAGE sql STABLE AS $$
 SELECT (item->>'line_no')::integer, sum((item->>'amount')::numeric),
        sum((item->>'cgst')::numeric), sum((item->>'sgst')::numeric), sum((item->>'igst')::numeric)
 FROM association_finance_notes n JOIN association_finance_vouchers v ON v.id=n.voucher_id
 CROSS JOIN LATERAL jsonb_array_elements(n.tax_correction->'charges') item
 WHERE n.original_document_id=original AND n.voucher_id IS DISTINCT FROM except_note AND v.status='posted'
 GROUP BY 1
$$;

CREATE FUNCTION filmunity_tax_note_plan(original uuid, except_note uuid, requested jsonb,
                                       tds numeric, review text) RETURNS jsonb
LANGUAGE plpgsql AS $$
DECLARE t association_finance_tax_documents; source association_finance_vouchers;
 party uuid; prior_tds numeric; prior_net numeric; gross numeric; net numeric;
 charges jsonb; lines jsonb; sign integer; charge_count integer; tds_account uuid;
BEGIN
 SELECT * INTO t FROM association_finance_tax_documents WHERE voucher_id=original;
 SELECT * INTO source FROM association_finance_vouchers WHERE id=original;
 SELECT party_account_id INTO party FROM association_bill_documents WHERE voucher_id=original;
 IF t.voucher_id IS NULL OR party IS NULL OR source.status<>'posted' THEN
  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Choose a posted tax document.';
 END IF;
 IF jsonb_typeof(requested) IS DISTINCT FROM 'array' OR jsonb_array_length(requested) NOT BETWEEN 1 AND 20 THEN
  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Select at least one original charge to correct.';
 END IF;
 IF (SELECT count(DISTINCT (x->>'line_no')::integer) FROM jsonb_array_elements(requested) x)<>jsonb_array_length(requested) THEN
  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Each original charge can appear only once per note.';
 END IF;
 WITH selected AS (
  SELECT c.*, (x->>'amount')::numeric AS correction,
         c.amount-coalesce(u.amount,0) AS available,
         c.cgst-coalesce(u.cgst,0) AS cg_left, c.sgst-coalesce(u.sgst,0) AS sg_left,
         c.igst-coalesce(u.igst,0) AS ig_left
  FROM jsonb_array_elements(requested) x
  JOIN filmunity_tax_note_charges(original) c ON c.line_no=(x->>'line_no')::integer
  LEFT JOIN filmunity_tax_note_used(original,except_note) u ON u.line_no=c.line_no
 ), calculated AS (
  -- Apply the cumulative rounded target minus prior adjustments. A final correction
  -- takes the exact frozen remainder. The floor handles earlier notes reversed out of order.
  SELECT *, CASE WHEN correction=available THEN cg_left ELSE greatest(0,cg_left-cgst+least(cgst,round((amount-available+correction)*gst_rate/200,2))) END AS cg,
            CASE WHEN correction=available THEN sg_left ELSE greatest(0,sg_left-sgst+least(sgst,round((amount-available+correction)*gst_rate/200,2))) END AS sg,
            CASE WHEN correction=available THEN ig_left ELSE greatest(0,ig_left-igst+least(igst,round((amount-available+correction)*gst_rate/100,2))) END AS ig
  FROM selected WHERE correction>0 AND correction=round(correction,2) AND correction<=available
 )
 SELECT count(*), sum(correction+cg+sg+ig),
        jsonb_agg(jsonb_build_object('line_no',line_no,'amount',correction,'cgst',cg,'sgst',sg,'igst',ig,
                  'description',description,'account_id',account_id,'treatment',treatment,'gst_rate',gst_rate) ORDER BY line_no)
 INTO charge_count,gross,charges FROM calculated;
 IF charge_count<>jsonb_array_length(requested) THEN
  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='A correction exceeds the remaining amount on an original charge or references an invalid charge.';
 END IF;
 SELECT coalesce(sum((n.tax_correction->>'tds')::numeric),0),
        coalesce(sum(filmunity_bill_amount(n.voucher_id)),0)
 INTO prior_tds,prior_net
 FROM association_finance_notes n JOIN association_finance_vouchers v ON v.id=n.voucher_id
 WHERE n.original_document_id=original AND n.voucher_id IS DISTINCT FROM except_note AND v.status='posted';
 IF tds IS NULL OR tds<0 OR tds<>round(tds,2) OR tds+prior_tds>t.tds
    OR (t.tds>0 AND nullif(btrim(review),'') IS NULL) THEN
  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Review the original TDS and enter a reversal within the remaining original deduction.';
 END IF;
 net:=gross-tds;
 IF net<=0 OR net+prior_net>t.net THEN
  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='The note must have a positive party credit within the original net amount. Review the TDS treatment.';
 END IF;

 -- Positive movements debit a ledger. Reverse only the frozen original ledger directions.
 sign:=CASE WHEN source.kind='invoice' THEN 1 ELSE -1 END;
 IF tds>0 THEN
  SELECT a.id INTO tds_account FROM association_finance_lines l
  JOIN association_finance_accounts a ON a.id=l.account_id
  WHERE l.voucher_id=original AND a.code LIKE 'TAX-TDS-%' AND l.credit_minor=t.tds*100;
  IF tds_account IS NULL THEN RAISE EXCEPTION 'Original TDS ledger unavailable'; END IF;
 END IF;
 WITH parts AS (
  SELECT (x->>'account_id')::uuid AS account_id,
         sign*((x->>'amount')::numeric + CASE WHEN sign=-1 AND NOT (t.snapshot->>'claim_itc')::boolean
         THEN (x->>'cgst')::numeric+(x->>'sgst')::numeric+(x->>'igst')::numeric ELSE 0 END) AS movement
  FROM jsonb_array_elements(charges) x
  UNION ALL SELECT party,-sign*net
  UNION ALL SELECT tds_account,tds WHERE tds>0
  UNION ALL
  SELECT a.id, sign*g.amount
  FROM (SELECT 'CGST' AS component,sum((x->>'cgst')::numeric) AS amount FROM jsonb_array_elements(charges) x
        UNION ALL SELECT CASE WHEN t.mode='utgst' THEN 'UTGST' ELSE 'SGST' END,sum((x->>'sgst')::numeric) FROM jsonb_array_elements(charges) x
        UNION ALL SELECT 'IGST',sum((x->>'igst')::numeric) FROM jsonb_array_elements(charges) x) g
  JOIN association_finance_accounts a ON a.association_id=t.association_id
       AND a.code=(CASE WHEN sign=1 THEN 'TAX-OUT-' ELSE 'TAX-IN-' END)||g.component
  JOIN association_finance_lines l ON l.account_id=a.id AND l.voucher_id=original
  WHERE g.amount>0 AND (sign=1 OR (t.snapshot->>'claim_itc')::boolean)
 ), grouped AS (
  SELECT account_id,sum(movement)*100 AS minor FROM parts GROUP BY account_id
 )
 SELECT jsonb_agg(jsonb_build_object('account_id',account_id,'debit_minor',greatest(minor,0),
                  'credit_minor',greatest(-minor,0)) ORDER BY account_id)
 INTO lines FROM grouped WHERE minor<>0;
 RETURN jsonb_build_object('charges',charges,'original_serial',t.serial,'mode',t.mode,
                           'tds',tds,'tds_review',review,'gross',gross,'net',net,'lines',lines);
END $$;

CREATE OR REPLACE FUNCTION filmunity_tax_note_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected jsonb;
BEGIN
 IF EXISTS(SELECT 1 FROM association_finance_tax_documents WHERE voucher_id=NEW.original_document_id) THEN
  expected:=filmunity_tax_note_plan(NEW.original_document_id,NEW.voucher_id,NEW.tax_correction->'charges',
                                  (NEW.tax_correction->>'tds')::numeric,NEW.tax_correction->>'tds_review');
  IF expected IS DISTINCT FROM NEW.tax_correction THEN RAISE EXCEPTION 'Tax correction differs from original charges'; END IF;
 ELSIF NEW.tax_correction IS NOT NULL THEN
  RAISE EXCEPTION 'A tax correction requires an original tax document';
 END IF;
 RETURN NEW;
END $$;

DROP TRIGGER finance_tax_note ON association_finance_notes;
CREATE TRIGGER finance_tax_note BEFORE INSERT OR UPDATE ON association_finance_notes
FOR EACH ROW EXECUTE FUNCTION filmunity_tax_note_guard();

-- Preserve the legacy identity and draft-only guard while allowing the draft snapshot to be revised.
DO $$
BEGIN
 EXECUTE replace(pg_get_functiondef('filmunity_note_guard()'::regprocedure), '-''reason''', '-''reason''-''tax_correction''');
END $$;

DO $$
BEGIN
 EXECUTE replace(pg_get_functiondef('filmunity_validate_note(uuid)'::regprocedure),
                 'filmunity_validate_note(', 'filmunity_validate_simple_note(');
END $$;

CREATE OR REPLACE FUNCTION filmunity_validate_note(note_id uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE n association_finance_notes; d association_bill_documents; original association_bill_documents;
 v association_finance_vouchers; source association_finance_vouchers; expected jsonb; actual jsonb;
BEGIN
 SELECT * INTO n FROM association_finance_notes WHERE voucher_id=note_id;
 IF n.tax_correction IS NULL THEN
  IF EXISTS(SELECT 1 FROM association_finance_tax_documents t WHERE t.voucher_id=n.original_document_id) THEN
   RAISE EXCEPTION 'Tax correction snapshot is required';
  END IF;
  PERFORM filmunity_validate_simple_note(note_id);
  RETURN;
 END IF;
 SELECT * INTO v FROM association_finance_vouchers WHERE id=note_id;
 SELECT * INTO source FROM association_finance_vouchers WHERE id=n.original_document_id;
 SELECT * INTO original FROM association_bill_documents WHERE voucher_id=source.id;
 SELECT * INTO d FROM association_bill_documents WHERE voucher_id=note_id;
 IF v.status IS DISTINCT FROM 'submitted' THEN
  RAISE EXCEPTION 'Submit the tax correction for review before posting';
 END IF;
 IF d.party_account_id IS DISTINCT FROM original.party_account_id
    OR n.association_id IS DISTINCT FROM source.association_id OR v.entry_date<source.entry_date
    OR NOT ((v.kind='credit_note' AND original.flow='invoice' AND d.flow='receipt')
         OR (v.kind='debit_note' AND original.flow='bill' AND d.flow='payment')) THEN
  RAISE EXCEPTION 'Tax correction must retain the original party, direction and date order';
 END IF;
 expected:=filmunity_tax_note_plan(n.original_document_id,n.voucher_id,n.tax_correction->'charges',
                                 (n.tax_correction->>'tds')::numeric,n.tax_correction->>'tds_review');
 SELECT jsonb_agg(jsonb_build_object('account_id',account_id,'debit_minor',debit_minor,
                  'credit_minor',credit_minor) ORDER BY account_id)
 INTO actual FROM association_finance_lines WHERE voucher_id=note_id;
 IF expected IS DISTINCT FROM n.tax_correction OR actual IS DISTINCT FROM expected->'lines' THEN
  RAISE EXCEPTION 'Tax correction posting differs from the reviewed charges or ledger lines';
 END IF;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain tax correction history. Use a reviewed forward change or coordinated backup recovery.');
    }
};
