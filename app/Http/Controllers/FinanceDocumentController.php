<?php

namespace App\Http\Controllers;

use App\Services\Accounting\AccountingMoney;
use App\Support\AssociationBranding;
use App\Support\AssociationFinance as Finance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FinanceDocumentController extends Controller
{
    public function show(Request $request, string $association, string $voucher)
    {
        Finance::owner($request->user(), $association);
        $record = DB::table('association_finance_vouchers')->where('association_id', $association)->where('id', $voucher)->whereIn('kind', ['invoice', 'bill', 'credit_note', 'debit_note'])->first();
        abort_unless($record, 404);
        if (DB::table('association_finance_tax_documents')->where('association_id', $association)->where('voucher_id', $voucher)->exists()) {
            return app(AssociationTaxController::class)->show($request, $association, $voucher);
        }
        $note = DB::table('association_finance_notes')->where('association_id', $association)->where('voucher_id', $voucher)->first();
        $original = $note ? DB::table('association_finance_vouchers')->where('association_id', $association)->where('id', $note->original_document_id)->first() : null;
        abort_if($note && ! $original, 404);
        $tax = $original ? DB::table('association_finance_tax_documents')->where('association_id', $association)->where('voucher_id', $original->id)->first() : null;
        $snapshot = $tax ? json_decode($tax->snapshot, true, flags: JSON_THROW_ON_ERROR) : [];
        $correction = $note?->tax_correction ? json_decode($note->tax_correction, true, flags: JSON_THROW_ON_ERROR) : null;
        $document = DB::table('association_bill_documents')->where('association_id', $association)->where('voucher_id', $voucher)->first();
        abort_unless($document, 404);
        $partyLine = DB::table('association_finance_lines')->where('voucher_id', $voucher)->where('account_id', $document->party_account_id)->first();
        abort_unless($partyLine, 404);
        $amount = AccountingMoney::fromMinor(abs((int) $partyLine->debit_minor - (int) $partyLine->credit_minor));
        $recurring = DB::table('association_invoice_template_runs')->where('association_id', $association)->where('voucher_id', $voucher)->value('snapshot');
        $recurring = $recurring ? json_decode($recurring, true, flags: JSON_THROW_ON_ERROR) : null;

        return response()->view('accounting.finance-document', compact('record', 'note', 'original', 'snapshot', 'correction', 'document', 'amount', 'recurring') + ['branding' => $snapshot['branding'] ?? AssociationBranding::data($association)])->header('Cache-Control', 'private, no-store');
    }
}
