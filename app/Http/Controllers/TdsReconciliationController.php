<?php

namespace App\Http\Controllers;

use App\Services\Accounting\TdsReconciliation as Tds;
use App\Support\AssociationFinance as Finance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class TdsReconciliationController extends Controller
{
    public function index(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);

        return Inertia::render('tds-reconciliation', [
            'association' => $association, 'liabilities' => Tds::liabilities($association),
            'remittances' => Tds::remittances($association),
            'challans' => DB::table('association_tds_challans')->where('association_id', $association)->orderByDesc('created_at')->get(),
            'matches' => DB::table('association_tds_matches')->where('association_id', $association)->orderByDesc('created_at')->get(),
        ]);
    }

    public function record(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        Tds::record($association, $request->user(), $request->validate([
            'client_token' => ['required', 'uuid'], 'remittance_line_id' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:160'], 'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'],
        ]));

        return back()->with('status', 'Challan linked to the posted remittance. No new accounting entry was created.');
    }

    public function match(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        Tds::match($association, $request->user(), $request->validate([
            'client_token' => ['required', 'uuid'], 'challan_id' => ['required', 'uuid'], 'document_id' => ['required', 'uuid'],
            'amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'],
        ]));

        return back()->with('status', 'TDS liability matched.');
    }

    public function release(Request $request, string $association, string $match)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Tds::release($association, $request->user(), $match, $data['reason']);

        return back()->with('status', 'Match released; audit history retained.');
    }
}
