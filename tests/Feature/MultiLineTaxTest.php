<?php

namespace Tests\Feature;

use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\AssociationTaxService as Tax;
use App\Services\Accounting\BillSettlementService as Bills;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\TestCase;

class MultiLineTaxTest extends TestCase
{
    use \Tests\Support\WorkspaceMutationRequests;

    use DatabaseTransactions;
    use FinanceWorkflowFixtures;

    public function test_multi_rate_invoice_posts_with_separate_charge_snapshots_and_one_party_entry(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $data = [...$this->data($id, $party), 'items' => $this->charges($id)];
        $v = Tax::save($id, $owner, $data);
        $this->assertSame($v, Tax::save($id, $owner, $data));
        $this->postTax($owner, $id, $v);
        $this->assertSame('195.50', Bills::remaining($v));
        $this->assertSame(3, DB::table('association_finance_tax_items')->where('voucher_id', $v)->count());
        $this->assertDatabaseHas('association_finance_tax_documents', ['voucher_id' => $v, 'calculation_version' => 2, 'cgst' => '10.25', 'sgst' => '10.25']);
        $this->assertSame(1, DB::table('association_finance_lines')->where('voucher_id', $v)->where('account_id', $party)->count());
        $this->actingAs($owner)->get("/association-finance/$id/tax/documents/$v")->assertOk()->assertSee('First reviewed charge')->assertSee('Reviewed exemption basis');
    }

    public function test_vendor_bill_uses_sum_of_reviewed_line_bases_for_one_tds_deduction(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Vendor', 'reference' => 'MULTI-V1', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $v = Tax::save($id, $owner, [...$this->data($id, $party, 'bill'), 'items' => $this->charges($id, true)]);
        $this->postTax($owner, $id, $v);
        $this->assertSame('185.50', Bills::remaining($v));
        $this->assertDatabaseHas('association_finance_tax_documents', ['voucher_id' => $v, 'tds_base' => '100.00', 'tds' => '10.00']);
    }

    public function test_api_accepts_charge_lines_without_legacy_scalar_charge_fields(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $data = $this->data($id, $party);
        foreach (['counter_account_id', 'taxable_base', 'exempt_base', 'gst_rate', 'tds_base', 'hsn_sac'] as $key) {
            unset($data[$key]);
        }$data['items'] = $this->charges($id);
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/tax/documents", $data)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('association_finance_tax_documents')->where('association_id', $id)->count());
    }

    public function test_api_rejects_more_than_twenty_lines_and_missing_charge_classification(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $data = $this->data($id, $party);
        $item = $this->charges($id)[0];
        $data['items'] = array_fill(0, 21, $item);
        $this->actingAs($owner)->postWithWorkspaceLease("/association-finance/$id/tax/documents", $data)->assertSessionHasErrors('items');
        $data['items'] = [array_diff_key($item, ['counter_account_id' => true])];
        $this->postWithWorkspaceLease("/association-finance/$id/tax/documents", $data)->assertSessionHasErrors('items.0.counter_account_id');
    }

    public function test_legacy_single_charge_and_multi_line_rounding_can_coexist(): void
    {
        [$owner,$id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $legacy = Tax::save($id, $owner, $this->data($id, $party));
        $this->postTax($owner, $id, $legacy);
        $this->assertDatabaseHas('association_finance_tax_documents', ['voucher_id' => $legacy, 'calculation_version' => 1, 'net' => '12800.00']);
        $data = $this->data($id, $party);
        $item = [...$this->charges($id)[0], 'amount' => '0.10'];
        $v = Tax::save($id, $owner, [...$data, 'items' => [$item, $item]]);
        $this->postTax($owner, $id, $v);
        $this->assertSame('0.24', Bills::remaining($v));
    }
}
