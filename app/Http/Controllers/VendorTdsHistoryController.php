<?php

namespace App\Http\Controllers;

use App\Services\Accounting\VendorTdsHistory;
use App\Support\AssociationFinance as Finance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VendorTdsHistoryController extends Controller
{
    public function show(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['vendor_id' => ['required', 'uuid'], 'book_date' => ['required', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:book_date'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:book_date']]);
        abort_unless(DB::table('association_finance_vendors')->where('association_id', $association)->where('id', $data['vendor_id'])->exists(), 404);
        $date = CarbonImmutable::parse($data['book_date']);
        $year = $date->year - ($date->month < 4 ? 1 : 0);
        $from = $data['from'] ?? "$year-04-01";
        $to = $data['to'] ?? $data['book_date'];
        Finance::check($from <= $to, 'History start date must not exceed the end date.');

        return response()->json(VendorTdsHistory::report($association, $data['vendor_id'], $from, $to))->header('Cache-Control', 'private, no-store');
    }
}
