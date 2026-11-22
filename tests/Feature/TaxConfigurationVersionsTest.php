<?php

namespace Tests\Feature;

use App\Services\Accounting\AssociationTaxService as Tax;
use App\Services\Accounting\TaxConfigurationVersions as Versions;
use App\Support\AssociationFinance as Finance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\TestCase;

class TaxConfigurationVersionsTest extends TestCase
{
    use DatabaseTransactions, FinanceWorkflowFixtures;

    public function test_effective_boundaries_duplicate_date_conflict_and_tenant_scope(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        $old = Versions::effective($id, '2026-11-01');
        $this->assertNull(Versions::effective($id, '2026-10-31'));
        $settings = (array) DB::table('association_finance_tax_settings')->where('association_id', $id)->first();
        unset($settings['association_id'], $settings['updated_by'], $settings['updated_at']);
        Tax::settings($id, $owner, [...$settings, 'effective_from' => '2026-11-15', 'policy_note' => 'Reviewed second configuration with supporting reference DEMO-TRANSITION']);
        $this->assertSame($old->configuration_version_id, Versions::effective($id, '2026-11-14')->configuration_version_id);
        $new = Versions::effective($id, '2026-11-15');
        $this->assertNotSame($old->configuration_version_id, $new->configuration_version_id);
        try {
            Tax::settings($id, $owner, [...$settings, 'effective_from' => '2026-11-15', 'policy_note' => 'Conflicting same date']);
            $this->fail('Conflict accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('finance', $error->errors());
        }
        [$other, $otherId] = $this->association(true);
        $this->assertNull(Versions::effective($otherId, '2026-11-20'));
        $this->actingAs($other)->get("/association-finance/$id/tax")->assertNotFound();
        $this->assertSame(2, DB::table('association_tax_configuration_versions')->where('association_id', $id)->count());
    }

    public function test_configuration_change_blocks_draft_posting_without_rewriting_posted_history(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $posted = Tax::save($id, $owner, $this->data($id, $party));
        $this->postTax($owner, $id, $posted);
        $draft = Tax::save($id, $owner, $this->data($id, $party));
        $snapshot = DB::table('association_finance_tax_documents')->where('voucher_id', $posted)->value('snapshot');
        $settings = (array) DB::table('association_finance_tax_settings')->where('association_id', $id)->first();
        unset($settings['association_id'], $settings['updated_by'], $settings['updated_at']);
        Tax::settings($id, $owner, [...$settings, 'effective_from' => '2026-11-15', 'policy_note' => 'Explicit reviewed determination DEMO-CHANGE']);
        $this->assertTrue(Versions::reviewRequired($id, $draft, '2026-11-20'));
        $this->actingAs($owner)->get("/association-finance/$id/tax")->assertOk()->assertSee('association-tax');
        Finance::decide($id, $draft, $owner, 'submit', 'Review for posting');
        try {
            Finance::decide($id, $draft, $owner, 'post', 'Stale attempt');
            $this->fail('Stale post accepted');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('review required', implode(' ', $error->errors()['finance']));
        }
        try {
            DB::transaction(fn () => DB::table('association_finance_vouchers')->where('id', $draft)->update(['status' => 'posted', 'posted_by' => $owner->id, 'posted_at' => now()]));
            $this->fail('SQL bypass accepted');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Tax configuration review required', $error->getMessage());
        }
        $this->assertSame($snapshot, DB::table('association_finance_tax_documents')->where('voucher_id', $posted)->value('snapshot'));
        $this->assertDatabaseHas('association_finance_vouchers', ['id' => $posted, 'status' => 'posted']);
        $fresh = Tax::save($id, $owner, $this->data($id, $party));
        $this->postTax($owner, $id, $fresh);
        $this->assertDatabaseHas('association_finance_tax_documents', ['voucher_id' => $fresh, 'net' => '12800.00']);
    }
}
