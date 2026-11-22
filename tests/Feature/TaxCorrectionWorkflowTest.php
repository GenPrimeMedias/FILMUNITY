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
use Tests\TestCase;

class TaxCorrectionWorkflowTest extends TestCase
{
    use \Tests\Support\WorkspaceMutationRequests;

    use DatabaseTransactions;
    use FinanceWorkflowFixtures;

    public function test_tax_credit_note_posts_partial_gst_and_enforces_charge_limits(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $original = Tax::save($id, $owner, [...$this->data($id, $party), 'items' => $this->charges($id)]);
        $this->postTax($owner, $id, $original);

        $data = $this->correction($original, 'credit_note', '60.00');
        $stale = Notes::save($id, $owner, [...$data, 'client_token' => (string) Str::uuid()]);
        $url = "/association-finance/$id/billing/notes";
        $this->actingAs($owner)->postWithWorkspaceLease($url, $data)->assertSessionHasNoErrors();
        $note = DB::table('association_bill_documents')->where('client_token', $data['client_token'])->value('voucher_id');
        $this->postWithWorkspaceLease("$url/$note", [...$data, 'reason' => 'Updated reconciliation'])->assertSessionHasNoErrors();
        $this->postTax($owner, $id, $note);
        $this->assertSame('70.80', Bills::remaining($note));
        $this->assertSame('195.50', Bills::remaining($original));
        $this->assertDatabaseHas('association_finance_events', ['voucher_id' => $note, 'action' => 'post', 'actor_id' => $owner->id]);
        $this->get("/association-finance/$id/billing?scope=corrections")->assertInertia(fn (Page $page) => $page
            ->component('association-billing')->where('taxSources.'.$original.'.charges.0.remaining', '40.00'));

        Finance::decide($id, $stale, $owner, 'submit', 'Review initiated');
        $this->postWithWorkspaceLease("/association-finance/$id/vouchers/$stale/decision", [
            'action' => 'post', 'confirmed' => true, 'note' => 'Stale review',
        ])->assertSessionHasErrors('charges');
        $this->assertDatabaseHas('association_finance_vouchers', ['id' => $stale, 'status' => 'submitted']);

        Bills::allocate($id, $owner, [
            'client_token' => (string) Str::uuid(), 'settlement_id' => $note, 'document_id' => $original,
            'amount' => '70.80', 'allocation_date' => '2026-11-20', 'note' => 'Apply approved credit',
        ]);
        $this->assertSame('124.70', Bills::remaining($original));
        $this->assertSame('0.00', Bills::remaining($note));
    }

    public function test_vendor_note_requires_tds_review_and_reverses_only_the_approved_amount(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Vendor', 'reference' => 'NOTE-V1', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $original = Tax::save($id, $owner, [...$this->data($id, $party, 'bill'), 'items' => $this->charges($id, true)]);
        $this->postTax($owner, $id, $original);

        $data = $this->correction($original, 'debit_note', '40.00');
        $url = "/association-finance/$id/billing/notes";
        $this->actingAs($owner)->postWithWorkspaceLease($url, $data)->assertSessionHasErrors('charges');
        $review = 'Reviewed deduction and supporting reconciliation V-01';
        $this->postWithWorkspaceLease($url, [...$data, 'tds_reversal' => '10.01', 'tds_review' => $review])->assertSessionHasErrors('charges');
        $note = Notes::save($id, $owner, [...$data, 'tds_reversal' => '4.00', 'tds_review' => $review]);
        $this->postTax($owner, $id, $note);
        $this->assertSame('43.20', Bills::remaining($note));
        $tdsAccount = DB::table('association_finance_accounts')->where('association_id', $id)->where('code', 'like', 'TAX-TDS-%')->value('id');
        $this->assertDatabaseHas('association_finance_lines', ['voucher_id' => $note, 'account_id' => $tdsAccount, 'debit_minor' => 400, 'credit_minor' => 0]);
        $this->assertDatabaseHas('association_finance_lines', ['voucher_id' => $note, 'account_id' => $party, 'debit_minor' => 4320, 'credit_minor' => 0]);
        $this->assertDatabaseHas('association_finance_tax_documents', ['voucher_id' => $original, 'tds' => '10.00']);

        $kept = Notes::save($id, $owner, [...$this->correction($original, 'debit_note', '10.00'), 'tds_review' => 'Retain deduction after review']);
        $this->postTax($owner, $id, $kept);
        $this->assertSame('11.80', Bills::remaining($kept));
        $this->assertDatabaseMissing('association_finance_lines', ['voucher_id' => $kept, 'account_id' => $tdsAccount]);
    }

    public function test_tax_correction_cannot_reference_another_association(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $original = Tax::save($id, $owner, $this->data($id, $party));
        $this->postTax($owner, $id, $original);
        [$other, $otherId] = $this->association(true);

        $this->actingAs($other)->postWithWorkspaceLease("/association-finance/$otherId/billing/notes", $this->correction($original, 'credit_note', '10.00'))
            ->assertSessionHasErrors('finance');
        $this->assertDatabaseMissing('association_finance_notes', ['association_id' => $otherId, 'original_document_id' => $original]);
    }

    public function test_legacy_tax_note_releases_charge_capacity_on_reversal(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $original = Tax::save($id, $owner, $this->data($id, $party));
        $this->postTax($owner, $id, $original);

        $data = $this->correction($original, 'credit_note', '10000.00');
        $note = Notes::save($id, $owner, $data);
        $this->postTax($owner, $id, $note);
        $this->assertSame('11800.00', Bills::remaining($note));
        Finance::decide($id, $note, $owner, 'reverse', 'Reviewed reversal', '2026-11-20');
        $replacement = Notes::save($id, $owner, [...$data, 'client_token' => (string) Str::uuid()]);
        $this->postTax($owner, $id, $replacement);
        $this->assertSame('11800.00', Bills::remaining($replacement));
    }

    public function test_database_rejects_tax_note_posting_with_changed_ledger_lines(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $original = Tax::save($id, $owner, [...$this->data($id, $party), 'items' => $this->charges($id)]);
        $this->postTax($owner, $id, $original);
        $note = Notes::save($id, $owner, $this->correction($original, 'credit_note', '10.00'));

        DB::table('association_finance_lines')->where('voucher_id', $note)->where('account_id', $party)->increment('credit_minor');
        Finance::decide($id, $note, $owner, 'submit', 'Review initiated');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Tax correction posting differs from the reviewed charges or ledger lines');
        DB::transaction(fn () => DB::table('association_finance_vouchers')->where('id', $note)->update([
            'status' => 'posted', 'posted_by' => $owner->id, 'posted_at' => now(),
        ]));
    }

    public function test_partial_notes_share_the_original_paise_rounding(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $item = [...$this->charges($id)[0], 'amount' => '0.10'];
        $original = Tax::save($id, $owner, [...$this->data($id, $party), 'items' => [$item]]);
        $this->postTax($owner, $id, $original);

        foreach ([['0.03', '0.03'], ['0.03', '0.05'], ['0.04', '0.04']] as [$base, $net]) {
            $note = Notes::save($id, $owner, $this->correction($original, 'credit_note', $base));
            $this->postTax($owner, $id, $note);
            $this->assertSame($net, Bills::remaining($note));
        }
        $this->assertSame('0.00', Notes::available($id, $original));
    }

    public function test_vendor_igst_without_input_credit_reduces_the_original_cost(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Vendor', 'reference' => 'NOTE-IGST', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        $item = [...$this->charges($id, true)[0], 'tds_base' => '0.00'];
        $original = Tax::save($id, $owner, [
            ...$this->data($id, $party, 'bill'), 'items' => [$item], 'mode' => 'igst', 'tds_rate' => '0.00',
            'supplier_state' => '29', 'party_gstin' => '29AAAAA0000A1Z5', 'claim_itc' => false,
        ]);
        $this->postTax($owner, $id, $original);

        $note = Notes::save($id, $owner, $this->correction($original, 'debit_note', '50.00'));
        $this->postTax($owner, $id, $note);
        $this->assertSame('59.00', Bills::remaining($note));
        $this->assertDatabaseHas('association_finance_lines', [
            'voucher_id' => $note, 'account_id' => $item['counter_account_id'], 'debit_minor' => 0, 'credit_minor' => 5900,
        ]);
        $this->assertSame(2, DB::table('association_finance_lines')->where('voucher_id', $note)->count());
    }
}
