<?php

namespace Tests\Feature;

use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\AssociationTaxService as Tax;
use App\Services\Accounting\CorrectionNoteService as Notes;
use App\Support\AssociationFinance as Finance;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\TestCase;

class TaxReportingAndDocumentsTest extends TestCase
{
    use DatabaseTransactions;
    use FinanceWorkflowFixtures;

    public function test_monthly_tax_report_reconciles_a_correction_and_reversal_in_separate_months(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $invoice = Tax::save($id, $owner, [...$this->data($id, $party), 'items' => $this->charges($id)]);
        $this->postTax($owner, $id, $invoice);

        Carbon::setTestNow('2027-01-20 12:00:00');
        CarbonImmutable::setTestNow('2027-01-20 12:00:00');
        $data = [...$this->correction($invoice, 'credit_note', '60.00'), 'entry_date' => '2026-12-20'];
        $note = Notes::save($id, $owner, $data);
        Notes::save($id, $owner, [...$data, 'client_token' => (string) Str::uuid(), 'charges' => [['line_no' => 1, 'amount' => '5.00']]]);
        $this->postTax($owner, $id, $note);
        Finance::decide($id, $note, $owner, 'reverse', 'Reviewed reversal', '2027-01-20');

        $this->actingAs($owner)->get("/association-finance/$id/tax?from=2026-11-01&to=2027-01-31")
            ->assertInertia(fn (Page $page) => $page->has('summary', 3)
                ->where('summary.0.net', '195.50')
                ->where('summary.1.kind', 'credit_note')
                ->where('summary.1.month', '2026-12')
                ->where('summary.1.taxable_base', '-60.00')
                ->where('summary.1.cgst', '-5.40')
                ->where('summary.1.sgst', '-5.40')
                ->where('summary.1.net', '-70.80')
                ->where('summary.2.month', '2027-01')
                ->where('summary.2.net', '70.80'));

        $this->assertDatabaseHas('association_finance_lines', ['voucher_id' => $note, 'account_id' => $party, 'credit_minor' => 7080]);
        $snapshot = DB::table('association_finance_notes')->where('voucher_id', $note)->first();
        $this->assertSame($invoice, $snapshot->original_document_id);
        $this->assertSame(1, json_decode($snapshot->tax_correction, true, flags: JSON_THROW_ON_ERROR)['charges'][0]['line_no']);
    }

    public function test_downloaded_v6_bundle_links_tax_correction_and_reconciles_in_paise(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$party] = $this->member($owner, $id);
        $original = Tax::save($id, $owner, [...$this->data($id, $party), 'items' => $this->charges($id)]);
        $this->postTax($owner, $id, $original);
        $note = Notes::save($id, $owner, $this->correction($original, 'credit_note', '60.00'));
        $this->postTax($owner, $id, $note);

        // Fixtures live in DatabaseTransactions; PostgreSQL cannot change isolation
        // after fixture queries. Keep rollback protection while exercising the stream.
        $database = \Mockery::mock(DB::getFacadeRoot());
        DB::swap($database);
        $database->shouldReceive('statement')
            ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY')
            ->once()->andReturn(true);
        $response = $this->actingAs($owner)->get("/association-finance/$id/export?dataset=bundle");
        $response->assertOk()->assertDownload("filmunity-finance-$id.json");
        $bundle = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('filmunity-association-finance/v6', $bundle['schema']);
        $this->assertSame($id, $bundle['association_id']);
        $snapshot = collect($bundle['notes'])->firstWhere('voucher_id', $note);
        $this->assertSame($original, $snapshot['original_document_id']);
        $plan = json_decode($snapshot['tax_correction'], true, flags: JSON_THROW_ON_ERROR);
        $source = collect($bundle['tax_documents'])->firstWhere('voucher_id', $original);
        $this->assertSame($source['serial'], $plan['original_serial']);
        $charge = collect($bundle['tax_items'])->where('voucher_id', $original)->firstWhere('line_no', $plan['charges'][0]['line_no']);
        $this->assertNotNull($charge);
        $this->assertSame(1, $plan['charges'][0]['line_no']);
        $minor = static function ($amount): int {
            [$whole, $fraction] = array_pad(explode('.', (string) $amount), 2, '');

            return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        };
        $this->assertSame(6000, $minor($plan['charges'][0]['amount']));
        $this->assertSame(7080, $minor($plan['net']));
        $this->assertSame($minor($plan['net']), $minor($plan['charges'][0]['amount']) + $minor($plan['charges'][0]['cgst']) + $minor($plan['charges'][0]['sgst']));
        foreach ([$original, $note] as $voucher) {
            $exported = collect($bundle['lines'])->where('voucher_id', $voucher)->sortBy('id')->values()->all();
            $stored = DB::table('association_finance_lines')->where('voucher_id', $voucher)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $this->assertEquals($stored, $exported);
            $this->assertSame(array_sum(array_column($exported, 'debit_minor')), array_sum(array_column($exported, 'credit_minor')));
        }
        $partyLine = collect($bundle['lines'])->where('voucher_id', $note)->firstWhere('account_id', $party);
        $this->assertSame(7080, (int) $partyLine['credit_minor']);
        [$other] = $this->association(true);
        $this->actingAs($other)->get("/association-finance/$id/export?dataset=bundle")->assertNotFound();
    }

    public function test_branded_document_views_use_saved_tax_and_correction_data_and_deny_other_associations(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        [$member] = $this->member($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Synthetic Vendor', 'reference' => 'PRINT-V1', 'email' => null]);
        $vendor = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->value('id');
        [$other, $otherId] = $this->association(true);
        foreach ([['invoice', $member, 'credit_note', '70.80'], ['bill', $vendor, 'debit_note', '64.80']] as [$flow, $party, $kind, $net]) {
            $original = Tax::save($id, $owner, [...$this->data($id, $party, $flow), 'items' => $this->charges($id, $flow === 'bill')]);
            $this->postTax($owner, $id, $original);
            $note = Notes::save($id, $owner, [...$this->correction($original, $kind, '60.00'), 'tds_reversal' => $flow === 'bill' ? '6.00' : '0.00', 'tds_review' => 'Synthetic deduction review']);
            $this->postTax($owner, $id, $note);
            $before = DB::table('association_finance_lines')->whereIn('voucher_id', [$original, $note])->orderBy('id')->get()->toJson();
            $this->actingAs($owner)->get("/association-finance/$id/billing/documents/$original")->assertOk()->assertSee('POSTED')->assertSee('Print / Save as PDF')->assertSee('First reviewed charge')->assertSee('Test Association');
            $this->actingAs($owner)->get("/association-finance/$id/billing/documents/$note")->assertOk()->assertSee('POSTED')->assertSee('Original document')->assertSee('Original charge references')->assertSee('First reviewed charge')->assertSee('5.40')->assertSee($net)->assertSee('Print / Save as PDF')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame($before, DB::table('association_finance_lines')->whereIn('voucher_id', [$original, $note])->orderBy('id')->get()->toJson());
            foreach ([$original, $note] as $voucher) {
                $this->actingAs($other)->get("/association-finance/$id/billing/documents/$voucher")->assertNotFound();
                $this->actingAs($other)->get("/association-finance/$otherId/billing/documents/$voucher")->assertNotFound();
            }
        }
    }
}
