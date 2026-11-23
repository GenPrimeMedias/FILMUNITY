<?php

namespace App\Http\Controllers;

use App\Services\Accounting\AccountingMasters;
use App\Support\AssociationFinance as Finance;
use App\Support\AssociationWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class AccountingMastersController extends Controller
{
    public function index(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        Finance::check(Finance::settings($association) !== null, 'Initialize finance first.');
        $f = $request->validate(['view' => ['nullable', Rule::in(['ledgers', 'members', 'vendors', 'groups'])], 'q' => ['nullable', 'string', 'max:120'], 'sort' => ['nullable', Rule::in(['name', 'code'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])]]);
        $view = $f['view'] ?? 'ledgers';
        if ($view === 'groups') {
            $q = DB::table('association_account_groups as a')->where('a.association_id', $association)->select('a.id', 'a.name', 'a.nature as classification', 'a.system_key as code', 'a.parent_id', 'a.is_system_default');
        } else {
            $q = DB::table('association_finance_accounts as a')->leftJoin('association_account_groups as g', 'g.id', '=', 'a.group_id')->leftJoin('association_finance_vendors as v', 'v.id', '=', 'a.vendor_id')->leftJoin('association_memberships as m', 'm.id', '=', 'a.membership_id')->leftJoin('users as u', 'u.id', '=', 'm.member_id')->where('a.association_id', $association)->select('a.id', 'a.code', 'a.name', 'a.type as classification', 'a.group_id', 'g.name as group_name', 'a.membership_id', 'a.vendor_id', 'v.reference as vendor_reference', 'v.email as vendor_email', 'm.number as member_number', 'u.name as member_name', 'a.active');
            if ($view === 'members') {
                $q->whereNotNull('a.membership_id');
            }if ($view === 'vendors') {
                $q->whereNotNull('a.vendor_id');
            }
        }
        $term = trim((string) ($f['q'] ?? ''));
        $f['q'] = $term;
        if ($term !== '') {
            foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) as $token) {
                $q->where(function ($query) use ($token, $view): void {
                    $columns = $view === 'groups' ? ['a.name', 'a.system_key'] : ['a.code', 'a.name', 'm.number', 'u.name', 'u.email', 'v.reference', 'v.email'];
                    foreach ($columns as $column) {
                        $query->orWhereRaw("strpos(lower(coalesce($column,'')),lower(?))>0", [$token]);
                    }
                });
            }
        }
        $sort = $f['sort'] ?? 'name';
        $column = $view === 'groups' && $sort === 'code' ? 'a.system_key' : 'a.'.$sort;

        return Inertia::render('accounting-masters', ['association' => AssociationWorkspace::forAdmin($request->user())->where('id', $association)->first(['id', 'name']), 'filters' => [...$f, 'view' => $view], 'records' => $q->orderBy($column, $f['direction'] ?? 'asc')->orderBy('a.id')->paginate(15)->withQueryString(), 'groups' => DB::table('association_account_groups')->where('association_id', $association)->orderBy('name')->get(['id', 'name', 'nature', 'system_key'])]);
    }

    public function group(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'nature' => ['required', Rule::in(['ASSET', 'LIABILITY', 'INCOME', 'EXPENSE'])], 'parent_id' => ['nullable', 'uuid']]);
        DB::transaction(function () use ($request, $association, $d): void {
            Finance::lock($association);
            Finance::check(Finance::settings($association) !== null, 'Initialize finance first.');
            Finance::check(! DB::table('association_account_groups')->where('association_id', $association)->where('name', $d['name'])->exists(), 'Group name is already in use.');
            if ($d['parent_id'] ?? null) {
                Finance::check(DB::table('association_account_groups')->where('association_id', $association)->where('id', $d['parent_id'])->where('nature', $d['nature'])->exists(), 'Choose a parent with the same association and nature.');
            }DB::table('association_account_groups')->insert(['id' => (string) Str::uuid(), 'association_id' => $association, ...$d, 'created_at' => now(), 'updated_at' => now()]);
            Finance::event($association, $request->user(), 'group_created', note: $d['name']);
        });

        return back()->with('status', 'Account group created.');
    }

    public function vendor(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'reference' => ['required', 'regex:/^[A-Z0-9_-]{1,60}$/D'], 'email' => ['nullable', 'email', 'max:254']]);
        AccountingMasters::vendor($association, $request->user(), $d);

        return back()->with('status', 'Vendor and linked creditor ledger created.');
    }

    public function sync(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $n = DB::transaction(function () use ($association, $request) {
            Finance::lock($association);
            Finance::check(Finance::settings($association) !== null, 'Initialize finance first.');
            $n = AccountingMasters::syncMembers($association);
            Finance::event($association, $request->user(), 'member_ledgers_synced', note: (string) $n);

            return $n;
        });

        return back()->with('status', "Created $n missing member ledgers. Existing balances are unchanged.");
    }

    public function classify(Request $request, string $association, string $account)
    {
        Finance::owner($request->user(), $association);
        $d = $request->validate(['group_id' => ['required', 'uuid']]);
        DB::transaction(function () use ($request, $association, $account, $d): void {
            Finance::lock($association);
            $a = Finance::account($association, $account);
            Finance::check(! $a->membership_id && ! $a->vendor_id, 'Party ledger groups are fixed.');
            $nature = $a->type === 'fund' ? 'LIABILITY' : strtoupper($a->type);
            Finance::check(DB::table('association_account_groups')->where('association_id', $association)->where('id', $d['group_id'])->where('nature', $nature)->exists(), 'Choose a group matching the ledger classification.');
            DB::table('association_finance_accounts')->where('id', $account)->update(['group_id' => $d['group_id'], 'updated_at' => now()]);
            Finance::event($association,$request->user(),'ledger_group_updated',note: json_encode(['account' => $account, 'from' => $a->group_id, 'to' => $d['group_id']],JSON_THROW_ON_ERROR));
        });

        return back()->with('status','Ledger group updated. Amounts and posted entries are unchanged.');
    }
}
