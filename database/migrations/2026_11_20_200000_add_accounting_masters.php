<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE association_account_groups (
 id uuid PRIMARY KEY, association_id uuid NOT NULL REFERENCES association_registration_applications(id) ON DELETE RESTRICT,
 parent_id uuid, name varchar(120) NOT NULL, nature varchar(12) NOT NULL CHECK (nature IN ('ASSET','LIABILITY','INCOME','EXPENSE')),
 system_key varchar(40), is_system_default boolean NOT NULL DEFAULT false,
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE (association_id,id), UNIQUE(association_id,name), UNIQUE(association_id,system_key),
 FOREIGN KEY(association_id,parent_id) REFERENCES association_account_groups(association_id,id) ON DELETE RESTRICT,
 CHECK(parent_id IS NULL OR parent_id <> id)
);
CREATE TABLE association_finance_vendors (
 id uuid PRIMARY KEY, association_id uuid NOT NULL REFERENCES association_registration_applications(id) ON DELETE RESTRICT,
 name varchar(120) NOT NULL, reference varchar(60) NOT NULL, email varchar(254), active boolean NOT NULL DEFAULT true,
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(association_id,id), UNIQUE(association_id,reference)
);
ALTER TABLE association_finance_accounts ALTER COLUMN code TYPE varchar(64),
 ADD COLUMN group_id uuid, ADD COLUMN membership_id uuid, ADD COLUMN vendor_id uuid,
 ADD CONSTRAINT finance_account_group_fk FOREIGN KEY(association_id,group_id) REFERENCES association_account_groups(association_id,id) ON DELETE RESTRICT,
 ADD CONSTRAINT finance_account_vendor_fk FOREIGN KEY(association_id,vendor_id) REFERENCES association_finance_vendors(association_id,id) ON DELETE RESTRICT,
 ADD CONSTRAINT finance_account_party_check CHECK (membership_id IS NULL OR vendor_id IS NULL),
 ADD CONSTRAINT finance_account_member_unique UNIQUE(association_id,membership_id),
 ADD CONSTRAINT finance_account_vendor_unique UNIQUE(association_id,vendor_id);
ALTER TABLE association_memberships ADD CONSTRAINT finance_membership_tenant_unique UNIQUE(association_id,id);
ALTER TABLE association_finance_accounts ADD CONSTRAINT finance_account_member_fk FOREIGN KEY(association_id,membership_id) REFERENCES association_memberships(association_id,id) ON DELETE RESTRICT;
-- Generated decimals preserve every historical paise and all existing posting integrations.
-- They are read-only projections, not a second balance or an independently editable opening amount.
ALTER TABLE association_finance_lines
 ADD COLUMN debit numeric(15,2) GENERATED ALWAYS AS ((debit_minor::numeric / 100)::numeric(15,2)) STORED,
 ADD COLUMN credit numeric(15,2) GENERATED ALWAYS AS ((credit_minor::numeric / 100)::numeric(15,2)) STORED,
 ADD CONSTRAINT finance_numeric_range CHECK (debit_minor <= 999999999999999 AND credit_minor <= 999999999999999);
CREATE INDEX finance_lines_account_voucher ON association_finance_lines(account_id,voucher_id);
CREATE INDEX finance_vouchers_org_date_id ON association_finance_vouchers(association_id,entry_date,id);
CREATE TABLE association_voucher_counters (
 association_id uuid NOT NULL REFERENCES association_registration_applications(id) ON DELETE RESTRICT,
 fy_start date NOT NULL CHECK (extract(month from fy_start)=4 AND extract(day from fy_start)=1),
 kind varchar(20) NOT NULL CHECK(kind IN ('receipt','payment','transfer','journal','opening','membership','reversal')),
 next_number bigint NOT NULL DEFAULT 1 CHECK(next_number BETWEEN 1 AND 1000000000),
 PRIMARY KEY(association_id,fy_start,kind)
);
CREATE OR REPLACE FUNCTION filmunity_group_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 -- Lock the tenant before hierarchy validation, serializing concurrent reparenting.
 PERFORM 1 FROM association_registration_applications WHERE id=NEW.association_id FOR UPDATE;
 IF TG_OP='UPDATE' AND (OLD.association_id<>NEW.association_id OR OLD.nature<>NEW.nature OR OLD.system_key IS DISTINCT FROM NEW.system_key) THEN
  RAISE EXCEPTION 'Group tenant, nature and system identity cannot change'; END IF;
 IF NEW.parent_id IS NOT NULL THEN
  IF NOT EXISTS(SELECT 1 FROM association_account_groups WHERE id=NEW.parent_id AND association_id=NEW.association_id AND nature=NEW.nature) THEN RAISE EXCEPTION 'Parent must belong to the same association and nature'; END IF;
  IF EXISTS(WITH RECURSIVE parents AS (
   SELECT id,parent_id FROM association_account_groups WHERE id=NEW.parent_id
   UNION SELECT g.id,g.parent_id FROM association_account_groups g JOIN parents p ON g.id=p.parent_id
  ) SELECT 1 FROM parents WHERE id=NEW.id) THEN RAISE EXCEPTION 'Account group hierarchy cannot contain a cycle'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_group_guard BEFORE INSERT OR UPDATE ON association_account_groups FOR EACH ROW EXECUTE FUNCTION filmunity_group_guard();
CREATE OR REPLACE FUNCTION filmunity_account_master_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE required_nature text;
BEGIN
 IF TG_OP='UPDATE' AND (OLD.association_id<>NEW.association_id OR OLD.type<>NEW.type OR OLD.is_money<>NEW.is_money OR OLD.membership_id IS DISTINCT FROM NEW.membership_id OR OLD.vendor_id IS DISTINCT FROM NEW.vendor_id) THEN
  RAISE EXCEPTION 'Ledger tenant, classification and party identity cannot change'; END IF;
 required_nature := CASE NEW.type WHEN 'fund' THEN 'LIABILITY' ELSE upper(NEW.type) END;
 IF NEW.group_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM association_account_groups WHERE id=NEW.group_id AND association_id=NEW.association_id AND nature=required_nature) THEN RAISE EXCEPTION 'Ledger group classification does not match'; END IF;
 IF NEW.membership_id IS NOT NULL AND (NEW.type<>'asset' OR NEW.is_money OR NOT EXISTS(SELECT 1 FROM association_account_groups WHERE id=NEW.group_id AND system_key='debtors')) THEN RAISE EXCEPTION 'Member ledger must use member receivables'; END IF;
 IF NEW.vendor_id IS NOT NULL AND (NEW.type<>'liability' OR NEW.is_money OR NOT EXISTS(SELECT 1 FROM association_account_groups WHERE id=NEW.group_id AND system_key='creditors')) THEN RAISE EXCEPTION 'Vendor ledger must use sundry creditors'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_account_master_guard BEFORE INSERT OR UPDATE ON association_finance_accounts FOR EACH ROW EXECUTE FUNCTION filmunity_account_master_guard();
CREATE OR REPLACE FUNCTION filmunity_finance_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_status text; v_association uuid;
BEGIN
 IF TG_TABLE_NAME = 'association_finance_events' THEN RAISE EXCEPTION 'Financial audit events are append only'; END IF;
 IF TG_TABLE_NAME = 'association_finance_vouchers' THEN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Financial vouchers must be retained'; END IF;
  IF OLD.status IN ('posted','reversed') AND NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND (to_jsonb(OLD) - 'status' - 'updated_at') = (to_jsonb(NEW) - 'status' - 'updated_at')) THEN RAISE EXCEPTION 'Posted vouchers are immutable; use a reversal'; END IF;
  RETURN NEW;
 END IF;
 SELECT status, association_id INTO v_status, v_association FROM association_finance_vouchers WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.voucher_id ELSE NEW.voucher_id END;
 IF TG_OP = 'DELETE' THEN
  IF v_status IN ('posted','reversed') THEN RAISE EXCEPTION 'Posted lines cannot be deleted'; END IF;
  RETURN OLD;
 END IF;
 IF NOT EXISTS (SELECT 1 FROM association_finance_accounts WHERE id = NEW.account_id AND association_id = v_association) THEN RAISE EXCEPTION 'Cross-association financial account rejected'; END IF;
 IF TG_OP = 'UPDATE' THEN
  IF OLD.voucher_id <> NEW.voucher_id THEN RAISE EXCEPTION 'Entry lines cannot move between vouchers'; END IF;
  IF v_status IN ('posted','reversed') AND (to_jsonb(OLD) - 'cleared_on' - 'statement_reference' - 'debit' - 'credit') <> (to_jsonb(NEW) - 'cleared_on' - 'statement_reference' - 'debit' - 'credit') THEN RAISE EXCEPTION 'Posted amounts cannot change'; END IF;
 END IF;
 IF TG_OP = 'INSERT' AND v_status IN ('posted','reversed') THEN RAISE EXCEPTION 'Cannot add lines to a posted voucher'; END IF;
 RETURN NEW;
END $$;

-- Backfill groups and classifications only; never rewrite posted vouchers or entries.
INSERT INTO association_account_groups(id,association_id,name,nature,system_key,is_system_default)
SELECT md5(s.association_id::text || ':' || d.key)::uuid,s.association_id,d.name,d.nature,d.key,true
FROM association_finance_settings s CROSS JOIN (VALUES
 ('assets','Assets','ASSET'),('liabilities','Liabilities','LIABILITY'),('income','Income','INCOME'),('expenses','Expenses','EXPENSE'),
 ('corpus','Corpus / accumulated fund','LIABILITY'),('sinking','Sinking fund','LIABILITY'),('debtors','Member receivables / Sundry debtors','ASSET'),
 ('creditors','Sundry creditors','LIABILITY'),('bank','Bank accounts','ASSET'),('cash','Cash-in-hand','ASSET'),
 ('subscriptions','Maintenance and subscription income','INCOME'),('indirect','Indirect expenses','EXPENSE'),('taxes','Duties and taxes','LIABILITY')
) AS d(key,name,nature);
UPDATE association_finance_accounts a SET group_id=g.id FROM association_account_groups g
WHERE g.association_id=a.association_id AND g.system_key=CASE
 WHEN a.system_key='cash' THEN 'cash' WHEN a.system_key='bank' THEN 'bank'
 WHEN a.type='fund' THEN 'corpus' WHEN a.type='income' THEN 'subscriptions' WHEN a.type='expense' THEN 'indirect'
 WHEN a.type='asset' THEN 'assets' ELSE 'liabilities' END;
-- Parent defaults can be nested after all self-referenced rows exist.
UPDATE association_account_groups g SET parent_id=p.id FROM association_account_groups p
WHERE p.association_id=g.association_id AND p.system_key=CASE g.nature WHEN 'ASSET' THEN 'assets' WHEN 'LIABILITY' THEN 'liabilities' WHEN 'INCOME' THEN 'income' ELSE 'expenses' END
AND g.system_key NOT IN ('assets','liabilities','income','expenses');
INSERT INTO association_finance_accounts(id,association_id,code,name,type,is_money,active,group_id,membership_id,created_at,updated_at)
SELECT md5('member-ledger:'||m.id::text)::uuid,m.association_id,'M-'||replace(m.id::text,'-',''),left(u.name||' / '||m.number,120),'asset',false,true,g.id,m.id,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
FROM association_memberships m JOIN users u ON u.id=m.member_id JOIN association_account_groups g ON g.association_id=m.association_id AND g.system_key='debtors';
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Accounting masters must be retained. Restore a coordinated backup instead of dropping financial data.');
    }
};
