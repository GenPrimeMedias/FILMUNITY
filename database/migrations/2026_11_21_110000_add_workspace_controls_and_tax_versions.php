<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_workspace_protection', function (Blueprint $table) {
            $table->uuid('association_id')->primary()->references('id')->on('association_registration_applications')->restrictOnDelete();
            $table->string('pin_hash')->nullable();
            $table->unsignedInteger('generation')->default(0);
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampsTz();
        });
        Schema::create('association_edit_leases', function (Blueprint $table) {
            $table->uuid('association_id')->references('id')->on('association_registration_applications')->restrictOnDelete();
            $table->string('resource', 40);
            $table->unsignedInteger('version')->default(0);
            $table->string('token_hash', 64)->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->primary(['association_id', 'resource']);
        });
        Schema::create('association_configuration_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('association_id')->references('id')->on('association_registration_applications')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('resource', 40);
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('association_mail_settings', function (Blueprint $table) {
            $table->uuid('association_id')->primary()->references('id')->on('association_registration_applications')->restrictOnDelete();
            $table->text('encrypted_configuration');
            $table->string('status', 40)->default('not_tested');
            $table->string('last_error', 240)->nullable();
            $table->timestampTz('tested_at')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::create('association_tax_configuration_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('association_id')->references('association_id')->on('association_finance_settings')->restrictOnDelete();
            $table->date('effective_from');
            $table->jsonb('reviewed_configuration');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at');
            $table->unique(['association_id', 'effective_from']);
        });
        // Retain the current reviewed configuration as one legacy version, without rewriting any document.
        DB::statement('INSERT INTO association_tax_configuration_versions(id,association_id,effective_from,reviewed_configuration,reviewed_by,reviewed_at) SELECT gen_random_uuid(),association_id,effective_from,to_jsonb(s),updated_by,updated_at FROM association_finance_tax_settings s');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION filmunity_configuration_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Reviewed configuration versions are retained; create a new effective date'; END $$;
CREATE TRIGGER configuration_version_immutable BEFORE UPDATE OR DELETE ON association_tax_configuration_versions FOR EACH ROW EXECUTE FUNCTION filmunity_configuration_version_immutable();
CREATE FUNCTION filmunity_tax_configuration_post_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE snapshot_version text; applicable uuid;
BEGIN
 IF NEW.status <> 'posted' OR OLD.status = 'posted' THEN RETURN NEW; END IF;
 SELECT snapshot->'supplier_profile'->>'configuration_version_id' INTO snapshot_version FROM association_finance_tax_documents WHERE voucher_id=NEW.id AND association_id=NEW.association_id;
 IF NOT FOUND THEN RETURN NEW; END IF;
 SELECT id INTO applicable FROM association_tax_configuration_versions WHERE association_id=NEW.association_id AND effective_from<=NEW.entry_date ORDER BY effective_from DESC LIMIT 1;
 IF snapshot_version IS NULL OR snapshot_version IS DISTINCT FROM applicable::text THEN RAISE EXCEPTION 'Tax configuration review required: reject and reissue the frozen draft under the applicable reviewed configuration'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER finance_tax_configuration_post BEFORE UPDATE OF status ON association_finance_vouchers FOR EACH ROW EXECUTE FUNCTION filmunity_tax_configuration_post_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retained security and tax review history requires a reviewed forward recovery; automatic rollback is not supported.');
    }
};
