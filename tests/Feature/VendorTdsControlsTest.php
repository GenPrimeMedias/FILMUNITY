<?php

namespace Tests\Feature;

use App\Services\Accounting\AccountingMasters;
use App\Services\Accounting\AssociationTaxService as Tax;
use App\Services\Accounting\BillSettlementService as Bills;
use App\Services\Accounting\CorrectionNoteService as Notes;
use App\Services\Accounting\VendorTdsReview;
use App\Support\AssociationFinance as Finance;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\TestCase;

class VendorTdsControlsTest extends TestCase
{
    use \Tests\Support\WorkspaceMutationRequests;

    use DatabaseTransactions;
    use FinanceWorkflowFixtures;

    public function test_vendor_tds_review_effective_dates_frozen_bill_snapshot_and_tenant_access(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Synthetic reviewed vendor', 'reference' => 'REVIEW-1', 'email' => null]);
        $account = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->first();
        $base = "/association-finance/$id/tax/vendor-reviews";
        $review = ['vendor_id' => $account->vendor_id, 'effective_from' => '2026-11-01', 'review_notes' => 'Synthetic reviewed services', 'supporting_reference' => 'SYNTHETIC-REVIEW-1', 'legal_reference' => '393(1) Table reviewed', 'reviewed_rate' => '10.00'];
        $this->actingAs($owner)->postWithWorkspaceLease($base, $review)->assertSessionHasNoErrors();
        $first = VendorTdsReview::effective($id, $account->vendor_id, '2026-11-20');
        $this->assertSame($owner->id, $first->reviewed_by);
        $this->assertNull(VendorTdsReview::effective($id, $account->vendor_id, '2026-10-31'));
        $this->postWithWorkspaceLease($base, [...$review, 'effective_from' => '2026-12-01', 'reviewed_rate' => '5.00'])->assertSessionHasNoErrors();
        $this->assertSame($first->id, VendorTdsReview::effective($id, $account->vendor_id, '2026-11-30')->id);
        $this->assertSame('5.00', VendorTdsReview::effective($id, $account->vendor_id, '2026-12-01')->reviewed_rate);
        $this->postWithWorkspaceLease($base, $review)->assertSessionHasErrors('finance');
        $data = [...$this->data($id, $account->id, 'bill'), 'items' => $this->charges($id, true), 'vendor_tds_review_id' => $first->id];
        $this->postWithWorkspaceLease("/association-finance/$id/tax/documents", [...$data, 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $bill = Tax::save($id, $owner, $data);
        $this->postTax($owner, $id, $bill);
        $before = DB::table('association_finance_tax_documents')->where('voucher_id', $bill)->value('snapshot');
        $saved = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($first->id, $saved['vendor_tds_review']['id']);
        $this->assertSame('10.00', $saved['vendor_tds_review']['reviewed_rate']);
        $this->assertSame('10.00', $saved['tds']);
        $this->postWithWorkspaceLease($base, [...$review, 'effective_from' => '2026-11-15', 'reviewed_rate' => '7.00', 'review_notes' => 'Later recorded review'])->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('association_finance_tax_documents')->where('voucher_id', $bill)->value('snapshot'));
        $this->get("/association-finance/$id/tax/documents/$bill")->assertOk()->assertSee('SYNTHETIC-REVIEW-1')->assertSee('Saved vendor TDS review context');
        $this->postWithWorkspaceLease("/association-finance/$id/tax/documents", [...$data, 'client_token' => (string) Str::uuid()])->assertSessionHasErrors('finance');
        [$other, $otherId] = $this->association(true);
        $this->actingAs($other)->get($base)->assertNotFound();
        $this->postWithWorkspaceLease($base, $review)->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$otherId/tax/vendor-reviews", $review)->assertSessionHasErrors('finance');
        $this->assertNull(VendorTdsReview::effective($otherId, $account->vendor_id, '2026-11-20'));
        $this->get("/association-finance/$otherId/tax")->assertInertia(fn (Page $p) => $p->has('vendorReviews', 0));
    }

    public function test_vendor_prior_tds_history_dates_drafts_reviewed_reversals_and_exact_paise(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Synthetic history vendor', 'reference' => 'HISTORY-1', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->first();
        $data = [...$this->data($id, $party->id, 'bill'), 'items' => $this->charges($id, true)];
        $bill = Tax::save($id, $owner, $data);
        $this->postTax($owner, $id, $bill);
        Tax::save($id, $owner, [...$data, 'client_token' => (string) Str::uuid()]);
        Notes::save($id, $owner, [...$this->correction($bill, 'debit_note', '10.00'), 'tds_reversal' => '1.00', 'tds_review' => 'Unposted review']);
        $retained = Notes::save($id, $owner, [...$this->correction($bill, 'debit_note', '10.00'), 'tds_review' => 'Retain reviewed TDS']);
        $this->postTax($owner, $id, $retained);
        $note = Notes::save($id, $owner, [...$this->correction($bill, 'debit_note', '20.00'), 'tds_reversal' => '2.01', 'tds_review' => 'Reviewed exact paise']);
        $this->postTax($owner, $id, $note);
        $url = "/association-finance/$id/tax/vendor-history?vendor_id=$party->vendor_id&book_date=2026-11-20";
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('from', '2026-04-01')->assertJsonPath('to', '2026-11-20')->assertJsonCount(3, 'movements')->assertJsonPath('totals.original_deductions', '10.00')->assertJsonPath('totals.reviewed_adjustments', '-2.01')->assertJsonPath('totals.net_deducted', '7.99');
        $this->getJson($url.'&from=2026-11-20&to=2026-11-20')->assertOk()->assertJsonPath('totals.net_deducted', '7.99');
        $this->getJson($url.'&to=2026-11-19')->assertOk()->assertJsonCount(0, 'movements')->assertJsonPath('totals.net_deducted', '0.00');
        Finance::decide($id, $note, $owner, 'reverse', 'Review correction reversal', '2026-11-21');
        $this->getJson($url)->assertJsonPath('totals.net_deducted', '7.99');
        $later = str_replace('book_date=2026-11-20', 'book_date=2026-11-21', $url);
        $this->getJson($later)->assertOk()->assertJsonCount(4, 'movements')->assertJsonPath('totals.reversal_movements', '2.01')->assertJsonPath('totals.net_deducted', '10.00');
        $this->getJson($later.'&from=2026-11-21')->assertJsonPath('totals.net_deducted', '2.01');
        $this->getJson(str_replace('book_date=2026-11-20', 'book_date=2027-03-31', $url))->assertJsonPath('from', '2026-04-01');
        $this->getJson(str_replace('book_date=2026-11-20', 'book_date=2027-04-01', $url))->assertJsonPath('from', '2027-04-01')->assertJsonPath('totals.net_deducted', '0.00');
        $this->getJson($url.'&to=2026-11-21')->assertUnprocessable();
        Finance::decide($id, $retained, $owner, 'reverse', 'Reverse retained-TDS correction', '2026-11-22');
        Finance::decide($id, $bill, $owner, 'reverse', 'Reverse source bill', '2026-11-22');
        $this->getJson(str_replace('book_date=2026-11-20', 'book_date=2026-11-22', $url))->assertOk()->assertJsonPath('totals.net_deducted', '0.00')->assertJsonPath('totals.reversal_movements', '-7.99');
        $this->getJson($url)->assertJsonPath('totals.net_deducted', '7.99');
        [$other, $otherId] = $this->association(true);
        $this->actingAs($other)->getJson($url)->assertNotFound();
        $this->getJson(str_replace("/$id/", "/$otherId/", $url))->assertNotFound();
    }

    public function test_vendor_certificate_inclusive_validity_private_evidence_and_frozen_snapshot(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Synthetic certificate vendor', 'reference' => 'CERT-V1', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->first();
        $url = "/association-finance/$id/tax/vendor-reviews";
        $review = ['vendor_id' => $party->vendor_id, 'effective_from' => '2026-11-01', 'review_notes' => 'Reviewed synthetic certificate', 'supporting_reference' => 'SYNTHETIC-EVIDENCE', 'legal_reference' => '393(1) Table reviewed', 'reviewed_rate' => '5.00', 'certificate_reference' => 'SYNTHETIC-CERT-1', 'valid_from' => '2026-11-10', 'valid_until' => '2026-11-20'];
        $this->actingAs($owner)->postWithWorkspaceLease($url, [...$review, 'evidence' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $certificate = DB::table('association_vendor_tds_reviews')->where('association_id', $id)->first();
        $this->get("$url/$certificate->id/evidence")->assertDownload('vendor-tds-evidence.pdf');
        $this->get($url)->assertInertia(fn (Page $p) => $p->missing('reviews.0.evidence_path')->where('reviews.0.certificate_reference', 'SYNTHETIC-CERT-1'));
        $billUrl = "/association-finance/$id/tax/documents";
        foreach (['2026-11-10', '2026-11-20'] as $date) {
            $data = [...$this->data($id, $party->id, 'bill'), 'items' => $this->charges($id, true), 'entry_date' => $date, 'tds_event_date' => $date, 'vendor_tds_certificate_id' => $certificate->id];
            $this->postWithWorkspaceLease($billUrl, $data)->assertSessionHasNoErrors();
            $bill = DB::table('association_bill_documents')->where('client_token', $data['client_token'])->value('voucher_id');
            $this->postTax($owner, $id, $bill);
        }
        $before = DB::table('association_finance_tax_documents')->where('voucher_id', $bill)->value('snapshot');
        $snapshot = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('SYNTHETIC-CERT-1', $snapshot['vendor_tds_certificate']['reference']);
        $this->assertSame('5.00', $snapshot['vendor_tds_certificate']['reviewed_rate']);
        $this->assertSame('10.00', $snapshot['tds_rate']);
        $this->assertStringNotContainsString($certificate->evidence_path, $before);
        $this->get("$billUrl/$bill")->assertOk()->assertSee('Saved vendor TDS certificate')->assertSee('SYNTHETIC-CERT-1')->assertSee('2026-11-10')->assertSee('2026-11-20');
        $this->postWithWorkspaceLease($url, [...$review, 'effective_from' => '2026-11-15', 'certificate_reference' => 'SYNTHETIC-CERT-2', 'reviewed_rate' => '3.00'])->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('association_finance_tax_documents')->where('voucher_id', $bill)->value('snapshot'));
        Carbon::setTestNow('2026-11-21');
        CarbonImmutable::setTestNow('2026-11-21');
        foreach (['2026-11-09', '2026-11-21'] as $date) {
            $this->postWithWorkspaceLease($billUrl, [...$data, 'client_token' => (string) Str::uuid(), 'entry_date' => $date, 'tds_event_date' => $date])->assertSessionHasErrors('finance');
        }
        $this->postWithWorkspaceLease($billUrl, [...$data, 'client_token' => (string) Str::uuid(), 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->postWithWorkspaceLease($url, [...$review, 'effective_from' => '2026-11-16', 'valid_until' => '2026-11-09'])->assertSessionHasErrors('valid_until');
        [$other, $otherId] = $this->association(true);
        $this->actingAs($other)->get("$url/$certificate->id/evidence")->assertNotFound();
        $this->get("/association-finance/$otherId/tax/vendor-reviews/$certificate->id/evidence")->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$otherId/tax/vendor-reviews", $review)->assertSessionHasErrors('finance');
        AccountingMasters::vendor($otherId, $other, ['name' => 'Other synthetic vendor', 'reference' => 'OTHER-CERT', 'email' => null]);
        $this->profile($other, $otherId);
        $foreignParty = DB::table('association_finance_accounts')->where('association_id', $otherId)->whereNotNull('vendor_id')->value('id');
        $this->postWithWorkspaceLease("/association-finance/$otherId/tax/documents", [...$this->data($otherId, $foreignParty, 'bill'), 'items' => $this->charges($otherId, true), 'vendor_tds_certificate_id' => $certificate->id])->assertSessionHasErrors('finance');
    }

    public function test_vendor_threshold_review_comparisons_boundaries_movements_and_incomplete_history(): void
    {
        [$owner, $id] = $this->association(true);
        $this->profile($owner, $id);
        AccountingMasters::vendor($id, $owner, ['name' => 'Synthetic threshold vendor', 'reference' => 'THRESHOLD-V1', 'email' => null]);
        $party = DB::table('association_finance_accounts')->where('association_id', $id)->whereNotNull('vendor_id')->first();
        $url = "/association-finance/$id/tax/vendor-threshold?vendor_id=$party->vendor_id&book_date=2026-11-20";
        $this->actingAs($owner)->getJson($url.'&draft_amount=25.00')->assertOk()->assertJsonPath('comparison', 'unavailable');
        $review = ['vendor_id' => $party->vendor_id, 'effective_from' => '2026-11-01', 'review_notes' => 'Synthetic accountant reviewed bill-basis comparison', 'legal_reference' => 'Reviewed synthetic rule', 'reviewed_rate' => '10.00', 'threshold_amount' => '200.00', 'threshold_basis' => 'pre_gst_vendor_bills', 'threshold_period' => 'april_march', 'threshold_accountant_reviewed' => true, 'threshold_history_reviewed' => true];
        $reviewUrl = "/association-finance/$id/tax/vendor-reviews";
        $this->postWithWorkspaceLease($reviewUrl, $review)->assertSessionHasNoErrors();
        $this->postWithWorkspaceLease($reviewUrl, [...$review, 'effective_from' => '2026-12-01', 'threshold_amount' => '300.00'])->assertSessionHasNoErrors();
        $data = [...$this->data($id, $party->id, 'bill'), 'items' => $this->charges($id, true)];
        $bill = Tax::save($id, $owner, $data);
        $this->postTax($owner, $id, $bill);
        Tax::save($id, $owner, [...$data, 'client_token' => (string) Str::uuid()]);
        $before = DB::table('association_finance_lines')->count();
        foreach ([['24.99', 'below', '199.99'], ['25.00', 'equal', '200.00'], ['25.01', 'above', '200.01']] as [$draft, $comparison, $projected]) {
            $this->getJson($url.'&draft_amount='.$draft)->assertOk()->assertJsonPath('eligible_posted_total', '175.00')->assertJsonPath('projected_total', $projected)->assertJsonPath('comparison', $comparison)->assertJsonCount(1, 'sources');
        }
        $this->assertSame($before, DB::table('association_finance_lines')->count());
        $this->getJson(str_replace('2026-11-20', '2026-10-31', $url).'&draft_amount=0.00')->assertJsonPath('comparison', 'unavailable');
        $this->getJson(str_replace('2026-11-20', '2026-11-01', $url).'&draft_amount=0.00')->assertJsonPath('eligible_posted_total', '0.00')->assertJsonPath('threshold', '200.00');
        $this->getJson(str_replace('2026-11-20', '2026-12-01', $url).'&draft_amount=0.00')->assertJsonPath('threshold', '300.00');
        $note = Notes::save($id, $owner, [...$this->correction($bill, 'debit_note', '20.00'), 'tds_review' => 'Retain TDS; reviewed charge reduction']);
        $this->postTax($owner, $id, $note);
        $this->getJson($url.'&draft_amount=45.00')->assertJsonPath('eligible_posted_total', '155.00')->assertJsonPath('comparison', 'equal')->assertJsonCount(2, 'sources');
        Finance::decide($id, $note, $owner, 'reverse', 'Reviewed correction reversal', '2026-11-21');
        $this->getJson($url.'&draft_amount=0.00')->assertJsonPath('eligible_posted_total', '155.00');
        $this->getJson(str_replace('2026-11-20', '2026-11-21', $url).'&draft_amount=0.00')->assertJsonPath('eligible_posted_total', '175.00');
        Finance::decide($id, $bill, $owner, 'reverse', 'Reviewed bill reversal', '2026-11-22');
        $this->getJson(str_replace('2026-11-20', '2026-11-22', $url).'&draft_amount=0.00')->assertJsonPath('eligible_posted_total', '0.00');
        $this->getJson(str_replace('2026-11-20', '2027-03-31', $url).'&draft_amount=0.00')->assertJsonPath('from', '2026-04-01');
        $this->getJson(str_replace('2026-11-20', '2027-04-01', $url).'&draft_amount=0.00')->assertJsonPath('from', '2027-04-01')->assertJsonCount(0, 'sources');
        $plain = Bills::save($id, $owner, [...$this->data($id, $party->id, 'bill'), 'amount' => '5.00']);
        $this->postTax($owner, $id, $plain);
        $this->getJson($url.'&draft_amount=0.00')->assertJsonPath('comparison', 'unavailable')->assertJsonPath('eligible_posted_total', null)->assertJsonPath('projected_total', null);
        $this->postWithWorkspaceLease($reviewUrl, [...$review, 'effective_from' => '2026-11-19', 'threshold_history_reviewed' => false])->assertSessionHasNoErrors();
        $this->getJson($url.'&draft_amount=0.00')->assertJsonPath('comparison', 'unavailable');
        [$other, $otherId] = $this->association(true);
        $this->actingAs($other)->getJson($url.'&draft_amount=0.00')->assertNotFound();
        $this->getJson(str_replace("/$id/", "/$otherId/", $url).'&draft_amount=0.00')->assertNotFound();
        $this->postWithWorkspaceLease("/association-finance/$otherId/tax/vendor-reviews", $review)->assertSessionHasErrors('finance');
    }
}
