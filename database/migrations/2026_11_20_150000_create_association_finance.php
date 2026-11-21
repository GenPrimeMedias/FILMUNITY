<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('association_finance_settings', function (Blueprint $t): void {
   $t->foreignUuid('association_id')->primary()->constrained('association_registration_applications')->restrictOnDelete();
   $t->date('starts_on'); $t->date('locked_through')->nullable(); $t->unsignedBigInteger('next_number')->default(1);
   $t->uuid('yearly_account_id')->nullable(); $t->uuid('lifetime_account_id')->nullable();
   $t->boolean('enabled')->default(false); $t->text('policy_note')->nullable(); $t->timestampsTz();
  });
  Schema::create('association_finance_accounts', function (Blueprint $t): void {
   $t->uuid('id')->primary(); $t->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
   $t->string('code', 20); $t->string('name', 120); $t->string('type', 12); $t->boolean('is_money')->default(false);
   $t->boolean('active')->default(true); $t->string('system_key', 30)->nullable(); $t->timestampsTz();
   $t->unique(['association_id', 'code']); $t->unique(['association_id', 'system_key']);
  });
  Schema::create('association_finance_vouchers', function (Blueprint $t): void {
   $t->uuid('id')->primary(); $t->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
   $t->string('number', 32); $t->date('entry_date'); $t->string('kind', 20); $t->string('status', 12)->default('draft');
   $t->text('description'); $t->string('party', 160)->nullable(); $t->string('reference', 160)->nullable();
   $t->string('source_type', 30)->nullable(); $t->uuid('source_id')->nullable();
   $t->uuid('reversal_of')->nullable(); $t->unique('reversal_of');
   $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
   $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestampTz('posted_at')->nullable(); $t->text('decision_note')->nullable();
   $t->string('evidence_path')->nullable(); $t->timestampsTz();
   $t->unique(['association_id', 'number']); $t->unique(['association_id', 'source_type', 'source_id'], 'finance_source_unique');
   $t->index(['association_id', 'entry_date', 'status']);
  });
  // Laravel adds UUID primary keys after other create-table commands.
  // Add this self-reference only after the voucher primary key exists.
  Schema::table('association_finance_vouchers', function (Blueprint $t): void {
   $t->foreign('reversal_of')->references('id')->on('association_finance_vouchers')->restrictOnDelete();
  });
  Schema::create('association_finance_lines', function (Blueprint $t): void {
   $t->bigIncrements('id'); $t->foreignUuid('voucher_id')->constrained('association_finance_vouchers')->restrictOnDelete();
   $t->foreignUuid('account_id')->constrained('association_finance_accounts')->restrictOnDelete();
   $t->bigInteger('debit_minor')->default(0); $t->bigInteger('credit_minor')->default(0);
   $t->date('cleared_on')->nullable(); $t->string('statement_reference', 160)->nullable();
  });
  Schema::create('association_finance_events', function (Blueprint $t): void {
   $t->bigIncrements('id'); $t->foreignUuid('association_id')->constrained('association_registration_applications')->restrictOnDelete();
   $t->foreignUuid('voucher_id')->nullable()->constrained('association_finance_vouchers')->restrictOnDelete();
   $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); $t->string('action', 40); $t->text('note')->nullable(); $t->timestampTz('created_at');
  });
  DB::statement("ALTER TABLE association_finance_accounts ADD CONSTRAINT finance_account_type CHECK (type IN ('asset','liability','fund','income','expense')), ADD CONSTRAINT finance_money_asset CHECK (NOT is_money OR type = 'asset')");
  DB::statement("ALTER TABLE association_finance_vouchers ADD CONSTRAINT finance_voucher_status CHECK (status IN ('draft','submitted','posted','reversed','rejected')), ADD CONSTRAINT finance_voucher_kind CHECK (kind IN ('receipt','payment','transfer','journal','opening','membership','reversal'))");
  DB::statement('ALTER TABLE association_finance_lines ADD CONSTRAINT finance_line_amount CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0))');
  Schema::table('association_finance_settings', function (Blueprint $t): void {
   $t->foreign('yearly_account_id')->references('id')->on('association_finance_accounts')->restrictOnDelete();
   $t->foreign('lifetime_account_id')->references('id')->on('association_finance_accounts')->restrictOnDelete();
  });
  DB::unprepared(<<<'SQL'
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
  IF v_status IN ('posted','reversed') AND (to_jsonb(OLD) - 'cleared_on' - 'statement_reference') <> (to_jsonb(NEW) - 'cleared_on' - 'statement_reference') THEN RAISE EXCEPTION 'Posted amounts cannot change'; END IF;
 END IF;
 IF TG_OP = 'INSERT' AND v_status IN ('posted','reversed') THEN RAISE EXCEPTION 'Cannot add lines to a posted voucher'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_voucher_immutable BEFORE UPDATE OR DELETE ON association_finance_vouchers FOR EACH ROW EXECUTE FUNCTION filmunity_finance_guard();
CREATE TRIGGER finance_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON association_finance_lines FOR EACH ROW EXECUTE FUNCTION filmunity_finance_guard();
CREATE TRIGGER finance_events_immutable BEFORE UPDATE OR DELETE ON association_finance_events FOR EACH ROW EXECUTE FUNCTION filmunity_finance_guard();
CREATE OR REPLACE FUNCTION filmunity_finance_balance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_id uuid; v_status text; d numeric; c numeric; n integer;
BEGIN
 IF TG_TABLE_NAME = 'association_finance_vouchers' THEN v_id := NEW.id;
 ELSIF TG_OP = 'DELETE' THEN v_id := OLD.voucher_id;
 ELSE v_id := NEW.voucher_id; END IF;
 SELECT status INTO v_status FROM association_finance_vouchers WHERE id = v_id;
 IF v_status IN ('posted','reversed') THEN
  SELECT COALESCE(SUM(debit_minor),0), COALESCE(SUM(credit_minor),0), COUNT(DISTINCT account_id) INTO d,c,n FROM association_finance_lines WHERE voucher_id = v_id;
  IF d <= 0 OR d <> c OR n < 2 THEN RAISE EXCEPTION 'Posted financial voucher must balance on at least two accounts'; END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER finance_voucher_balance AFTER INSERT OR UPDATE ON association_finance_vouchers DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION filmunity_finance_balance();
CREATE CONSTRAINT TRIGGER finance_lines_balance AFTER INSERT OR UPDATE OR DELETE ON association_finance_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION filmunity_finance_balance();
SQL);
 }
 public function down(): void { throw new RuntimeException('Financial records must be retained. Restore a coordinated verified backup rather than dropping the ledger.'); }
};
