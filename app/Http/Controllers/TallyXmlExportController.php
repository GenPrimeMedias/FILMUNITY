<?php

namespace App\Http\Controllers;

use App\Services\Accounting\TallyXmlExport;
use App\Support\AssociationFinance as Finance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class TallyXmlExportController extends Controller
{
    private function dates(Request $request): array
    {
        $dates = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $today = CarbonImmutable::now(config('app.timezone'));
        $from = $dates['from'] ?? $today->startOfMonth()->toDateString();
        $to = $dates['to'] ?? $today->toDateString();
        Finance::check($from <= $to, 'Start date must not exceed end date.');

        return [$from, $to];
    }

    public function index(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        [$from, $to] = $this->dates($request);

        return Inertia::render('tally-xml-export', ['association' => $association, 'batch' => TallyXmlExport::preview($association, $from, $to),
            'accounts' => DB::table('association_finance_accounts')->where('association_id', $association)->whereNull('membership_id')->whereNull('vendor_id')->where('code', 'not like', 'TAX-%')->orderBy('name')->get(['id', 'code', 'name']),
            'groups' => TallyXmlExport::GROUPS, 'mappings' => DB::table('association_tally_ledger_mappings')->where('association_id', $association)->orderBy('ledger_name')->get()]);
    }

    public function mapping(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['account_id' => ['required', 'uuid'], 'ledger_name' => ['required', 'string', 'max:120'], 'parent_group' => ['required', Rule::in(TallyXmlExport::GROUPS)], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($association, $request, $data) {
            Finance::lock($association);
            Finance::owner($request->user(), $association);
            $account = Finance::account($association, $data['account_id']);
            Finance::check(! $account->membership_id && ! $account->vendor_id && ! str_starts_with($account->code, 'TAX-'), 'Party and tax ledgers are outside this basic export.');
            $name = trim($data['ledger_name']);
            $key = mb_strtolower($name);
            Finance::check($name !== '' && ! DB::table('association_tally_ledger_mappings')->where('association_id', $association)->where('name_key', $key)->where('account_id', '<>', $account->id)->exists(), 'Use a distinct verified target ledger name for every source ledger.');
            $mapping = ['ledger_name' => $name, 'name_key' => $key, 'parent_group' => $data['parent_group'], 'verified_by' => $request->user()->getAuthIdentifier(), 'verified_at' => now()];
            DB::table('association_tally_ledger_mappings')->updateOrInsert(['association_id' => $association, 'account_id' => $account->id], $mapping);
            Finance::event($association, $request->user(), 'tally_mapping_verified', note: json_encode(['account' => $account->id, 'mapping' => $mapping], JSON_THROW_ON_ERROR));
        });

        return back()->with('status', 'Ledger mapping verified. Refresh and review the export batch.');
    }

    public function download(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        [$from, $to] = $this->dates($request);
        $data = $request->validate(['dataset' => ['required', Rule::in(['masters', 'vouchers', 'reconciliation'])], 'fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);
        $batch = TallyXmlExport::preview($association, $from, $to);
        abort_unless(hash_equals($batch['fingerprint'], $data['fingerprint']), 409, 'The batch changed. Refresh and review the preview before downloading.');
        $dataset = $data['dataset'];
        $body = $dataset === 'reconciliation' ? json_encode($batch, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) : TallyXmlExport::xml($batch, $dataset);
        $extension = $dataset === 'reconciliation' ? 'json' : 'xml';

        return response($body)->header('Content-Type', $extension === 'xml' ? 'application/xml; charset=UTF-8' : 'application/json')
            ->header('Content-Disposition', 'attachment; filename="filmunity-tally-'.$association.'-'.$from.'-'.$to.'-'.$dataset.'.'.$extension.'"')->header('Cache-Control', 'private, no-store');
    }
}
