<?php

namespace App\Http\Controllers;

use App\Services\Accounting\VendorTdsThreshold;
use App\Support\AssociationFinance as Finance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VendorTdsThresholdController extends Controller
{
    public function show(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['vendor_id' => ['required', 'uuid'], 'book_date' => ['required', 'date_format:Y-m-d'], 'draft_amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D']]);
        abort_unless(DB::table('association_finance_vendors')->where('association_id', $association)->where('id', $data['vendor_id'])->exists(), 404);

        return response()->json(VendorTdsThreshold::report($association, $data['vendor_id'], $data['book_date'], $data['draft_amount']))->header('Cache-Control', 'private, no-store');
    }
}
