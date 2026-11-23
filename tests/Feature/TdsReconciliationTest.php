<?php

namespace Tests\Feature;

use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\AssociationTaxService as Tax;
use App\Services\Accounting\BillSettlementService as Bills;
use App\Services\Accounting\CorrectionNoteService as Notes;
use App\Support\AssociationFinance as Finance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\Support\WorkspaceMutationRequests;
use Tests\TestCase;

class TdsReconciliationTest extends TestCase
{
    use DatabaseTransactions;
    use FinanceWorkflowFixtures;
    use WorkspaceMutationRequests;

    public function test_help_vendor_example_tax_correction_and_challan_reconcile_exactly(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Stagecraft Example', 'reference' => 'HELP-DEMO', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $charge = [...$this->charges($id, true)[0], 'amount' => '1000.00', 'tds_base' => '1000.00'];
        $bill = Tax::save($id, $owner, [...$this->data($id, $party, 'bill'), 'items' => [$charge]]);
        $this->postTax($owner, $id, $bill);
        $this->assertDatabaseHas('association_finance_tax_documents', ['voucher_id' => $bill, 'cgst' => '90.00', 'sgst' => '90.00', 'gross' => '1180.00', 'tds' => '100.00', 'net' => '1080.00']);
        $tds = DB::table('association_finance_accounts')->where('association_id', $id)->where('code', 'like', 'TAX-TDS-%')->value('id');
        $bank = DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', 'bank')->value('id');
        $payment = DB::transaction(function () use ($id, $owner, $tds, $bank) {
            Finance::lock($id);

            return Finance::insert($id, $owner, ['kind' => 'payment', 'entry_date' => '2026-11-20', 'description' => 'Help synthetic remittance'], Finance::lines($id, [['account_id' => $tds, 'debit' => '100.00'], ['account_id' => $bank, 'credit' => '100.00']], 'payment'));
        });
        $this->postTax($owner, $id, $payment);
        $line = DB::table('association_finance_lines')->where('voucher_id', $payment)->where('account_id', $tds)->value('id');
        $base = "/association-finance/$id/tax/challans";
        $this->actingAs($owner)->postWithWorkspaceLease($base, ['client_token' => (string) Str::uuid(), 'reference' => 'DEMO-CHALLAN-01', 'paid_on' => '2026-11-20', 'amount' => '100.00', 'remittance_line_id' => $line])->assertSessionHasNoErrors();
        $challan = DB::table('association_tds_challans')->where('association_id', $id)->value('id');
        $count = DB::table('association_finance_lines')->count();
        foreach (['40.00', '60.00'] as $amount) {
            $this->postWithWorkspaceLease("$base/matches", ['client_token' => (string) Str::uuid(), 'challan_id' => $challan, 'document_id' => $bill, 'amount' => $amount])->assertSessionHasNoErrors();
        }
        $this->get($base)->assertInertia(fn (Page $p) => $p->where('liabilities.0.reconciled_minor', 10000)->where('liabilities.0.outstanding_minor', 0));
        $this->assertSame($count, DB::table('association_finance_lines')->count());
        foreach (DB::table('association_tds_matches')->where('challan_id', $challan)->pluck('id') as $match) {
            $this->postWithWorkspaceLease("$base/matches/$match/release", ['reason' => 'Help alternative correction example'])->assertSessionHasNoErrors();
        }
        $note = Notes::save($id, $owner, [...$this->correction($bill, 'debit_note', '200.00'), 'tds_reversal' => '20.00', 'tds_review' => 'Help reviewed alternative']);
        $this->postTax($owner, $id, $note);
        $snapshot = json_decode(DB::table('association_finance_notes')->where('voucher_id', $note)->value('tax_correction'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('216.00', Bills::remaining($note));
        $this->postWithWorkspaceLease("$base/matches", ['client_token' => (string) Str::uuid(), 'challan_id' => $challan, 'document_id' => $bill, 'amount' => '80.00'])->assertSessionHasNoErrors();
        $this->get($base)->assertInertia(fn (Page $p) => $p->where('liabilities.0.adjusted_minor', 8000)->where('liabilities.0.reconciled_minor', 8000)->where('liabilities.0.outstanding_minor', 0));
        $this->assertNotEmpty($snapshot);
    }

    public function test_tds_challan_partial_full_release_idempotency_and_tenant_guards(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Synthetic TDS vendor', 'reference' => 'TDS-TEST', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $bill = Tax::save($id, $owner, [...$this->data($id, $party, 'bill'), 'items' => $this->charges($id, true)]);
        $this->postTax($owner, $id, $bill);
        $note = Notes::save($id, $owner, [...$this->correction($bill, 'debit_note', '40.00'), 'tds_reversal' => '4.00', 'tds_review' => 'Reviewed synthetic deduction reversal']);
        $this->postTax($owner, $id, $note);
        $tds = DB::table('association_finance_accounts')->where('association_id', $id)->where('code', 'like', 'TAX-TDS-%')->value('id');
        $bank = DB::table('association_finance_accounts')->where('association_id', $id)->where('system_key', 'bank')->value('id');
        $payment = DB::transaction(function () use ($id, $owner, $tds, $bank) {
            Finance::lock($id);

            return Finance::insert($id, $owner, ['kind' => 'payment', 'entry_date' => '2026-11-20', 'description' => 'Synthetic TDS remittance'], Finance::lines($id, [['account_id' => $tds, 'debit' => '6.00'], ['account_id' => $bank, 'credit' => '6.00']], 'payment'));
        });
        $this->postTax($owner, $id, $payment);
        $line = DB::table('association_finance_lines')->where('voucher_id', $payment)->where('account_id', $tds)->value('id');
        $base = "/association-finance/$id/tax/challans";
        $data = ['client_token' => (string) Str::uuid(), 'reference' => 'SYNTHETIC-CHALLAN-1', 'paid_on' => '2026-11-20', 'amount' => '6.00', 'remittance_line_id' => $line];
        $before = DB::table('association_finance_lines')->count();
        $this->actingAs($owner)->postWithWorkspaceLease($base, $data)->assertSessionHasNoErrors();
        $this->postWithWorkspaceLease($base, $data)->assertSessionHasNoErrors();
        $challan = DB::table('association_tds_challans')->where('association_id', $id)->value('id');
        $this->assertSame(1, DB::table('association_tds_challans')->where('association_id', $id)->count());
        $this->postWithWorkspaceLease($base, [...$data, 'client_token' => (string) Str::uuid()])->assertSessionHasErrors('finance');
        $match = ['client_token' => (string) Str::uuid(), 'challan_id' => $challan, 'document_id' => $bill, 'amount' => '2.01'];
        $this->postWithWorkspaceLease("$base/matches", $match)->assertSessionHasNoErrors();
        $this->postWithWorkspaceLease("$base/matches", $match)->assertSessionHasNoErrors();
        $this->get($base)->assertInertia(fn (Page $p) => $p->component('tds-reconciliation')->where('liabilities.0.adjusted_minor', 600)->where('liabilities.0.reconciled_minor', 201)->where('liabilities.0.outstanding_minor', 399));
        $this->postWithWorkspaceLease("$base/matches", [...$match, 'client_token' => (string) Str::uuid(), 'amount' => '4.00'])->assertSessionHasErrors('finance');
        $this->postWithWorkspaceLease("$base/matches", [...$match, 'client_token' => (string) Str::uuid(), 'amount' => '3.99'])->assertSessionHasNoErrors();
        $this->get($base)->assertInertia(fn (Page $p) => $p->where('liabilities.0.outstanding_minor', 0)->where('liabilities.0.reconciled_minor', 600));
        $vendorId = DB::table('association_finance_accounts')->where('id', $party)->value('vendor_id');
        $this->getJson("/association-finance/$id/tax/vendor-history?vendor_id=$vendorId&book_date=2026-11-20")
            ->assertOk()->assertJsonPath('totals.net_deducted', '6.00')->assertJsonCount(1, 'payments')->assertJsonPath('payments.0.vendor_matched_minor', '600');
        $this->assertSame($before, DB::table('association_finance_lines')->count());
        $this->postWithWorkspaceLease("/association-finance/$id/vouchers/$payment/decision", ['action' => 'reverse', 'entry_date' => '2026-11-20', 'note' => 'Incorrect remittance', 'confirmed' => true])->assertSessionHasErrors('finance');
        $extra = Notes::save($id, $owner, [...$this->correction($bill, 'debit_note', '10.00'), 'tds_reversal' => '1.00', 'tds_review' => 'Reviewed further reversal']);
        Finance::decide($id, $extra, $owner, 'submit', 'Reviewed');
        try {
            DB::transaction(fn () => DB::table('association_finance_vouchers')->where('id', $extra)->update(['status' => 'posted', 'posted_by' => $owner->id, 'posted_at' => now()]));
            $this->fail('A reviewed adjustment must not invalidate an active TDS match.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Release TDS matches', $e->getMessage());
        }
        [$other, $otherId] = $this->association(true);
        $this->actingAs($other)->get($base)->assertNotFound();
        $this->postWithWorkspaceLease($base, $data)->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$otherId/tax/challans", $data)->assertSessionHasErrors('finance');
        $this->postWithWorkspaceLease("/association-finance/$otherId/tax/challans/matches", $match)->assertNotFound();
        $matchId = DB::table('association_tds_matches')->where('client_token', $match['client_token'])->value('id');
        $this->postWithWorkspaceLease("/association-finance/$otherId/tax/challans/matches/$matchId/release", ['reason' => 'Foreign'])->assertNotFound();
        $this->actingAs($owner)->postWithWorkspaceLease("$base/matches/$matchId/release", ['reason' => 'Incorrect match'])->assertSessionHasNoErrors();
        $this->postWithWorkspaceLease("$base/matches/$matchId/release", ['reason' => 'Retry'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_tds_matches', ['id' => $matchId, 'release_reason' => 'Incorrect match', 'released_by' => $owner->id]);
        $this->get($base)->assertInertia(fn (Page $p) => $p->where('liabilities.0.outstanding_minor', 201));
        $secondId = DB::table('association_tds_matches')->where('association_id', $id)->whereNull('released_at')->value('id');
        $this->postWithWorkspaceLease("$base/matches/$secondId/release", ['reason' => 'Release before correcting payment'])->assertSessionHasNoErrors();
        Finance::decide($id, $payment, $owner, 'reverse', 'Incorrect remittance', '2026-11-20');
        $this->assertDatabaseHas('association_finance_vouchers', ['id' => $payment, 'status' => 'reversed']);
        $this->postWithWorkspaceLease("$base/matches", [...$match, 'client_token' => (string) Str::uuid()])->assertSessionHasErrors('finance');
    }
}
