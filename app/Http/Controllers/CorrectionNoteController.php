<?php

namespace App\Http\Controllers;

use App\Services\Accounting\CorrectionNoteService;
use App\Support\AssociationFinance as Finance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class CorrectionNoteController extends Controller
{
    public function save(Request $request, string $association, ?string $voucher = null)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['client_token' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['credit_note', 'debit_note'])], 'original_document_id' => ['required', 'uuid'], 'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'amount' => ['nullable', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'], 'charges' => ['nullable', 'array', 'list', 'min:1', 'max:20'], 'charges.*' => ['required', 'array:line_no,amount'], 'charges.*.line_no' => ['required', 'integer', 'between:1,20', 'distinct'], 'charges.*.amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'], 'tds_reversal' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'], 'tds_review' => ['nullable', 'string', 'max:2000'], 'reason' => ['required', 'string', 'max:2000'], 'reference' => ['nullable', 'string', 'max:160'], 'evidence' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'extensions:pdf,png,jpg,jpeg', 'max:5120']]);
        $old = $voucher ? DB::table('association_finance_vouchers')->where('association_id', $association)->where('id', $voucher)->value('evidence_path') : null;
        $path = $request->file('evidence')?->store('association-finance-evidence', 'local');
        Finance::check($path !== false, 'Evidence could not be stored.');
        try {
            $id = CorrectionNoteService::save($association, $request->user(), [...$data, 'evidence_path' => $path], $voucher);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
        if ($path && DB::table('association_finance_vouchers')->where('id', $id)->value('evidence_path') !== $path) {
            Storage::disk('local')->delete($path);
        } elseif ($path && $old) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('status','Correction note draft saved. Review and post, then apply the credit to an outstanding bill.');
    }
}
