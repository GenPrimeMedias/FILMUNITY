<?php

namespace App\Http\Controllers;

use App\Services\Accounting\AccountingMasters;
use App\Support\AssociationFinance as Finance;
use App\Support\AssociationWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class AssociationFinanceController extends Controller
{
    public function home(Request $request)
    {
        $request->validate(['tab' => ['nullable', Rule::in(['overview', 'vouchers', 'accounts', 'reports', 'ledger', 'settings'])]]);
        $id = AssociationWorkspace::forAdmin($request->user())->orderBy('id')->value('id');
        abort_if(! $id, 403);

        return redirect('/association-finance/'.$id.($request->query('tab') ? '?tab='.urlencode((string) $request->query('tab')) : ''));
    }

    public function index(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $filters = $request->validate(['tab' => ['nullable', Rule::in(['overview', 'vouchers', 'accounts', 'reports', 'ledger', 'settings'])], 'q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['draft', 'submitted', 'posted', 'reversed', 'rejected'])], 'sort' => ['nullable', Rule::in(['number', 'entry_date', 'status', 'kind'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'account' => ['nullable', 'uuid']]);
        $settings = Finance::settings($association);
        $to = $filters['to'] ?? today()->toDateString();
        $from = $filters['from'] ?? $settings?->starts_on ?? today()->startOfMonth()->toDateString();
        Finance::check($from <= $to, 'Report start date must not follow the end date.');
        $accounts = DB::table('association_finance_accounts as a')->leftJoin('association_finance_vendors as vendor', 'vendor.id', '=', 'a.vendor_id')->leftJoin('association_memberships as member', 'member.id', '=', 'a.membership_id')->where('a.association_id', $association)->orderBy('a.code')->get(['a.*', 'vendor.reference as vendor_reference', 'member.number as member_number']);
        $q = DB::table('association_finance_vouchers')->where('association_id', $association);
        if (! empty($filters['q'])) {
            $q->where(function ($q) use ($filters): void {
                $q->whereRaw('strpos(lower(number), lower(?)) > 0', [$filters['q']])->orWhereRaw('strpos(lower(description), lower(?)) > 0', [$filters['q']])->orWhereRaw("strpos(lower(coalesce(reference,'')), lower(?)) > 0", [$filters['q']])->orWhereRaw("strpos(lower(coalesce(party,'')), lower(?)) > 0", [$filters['q']]);
            });
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        $q->whereBetween('entry_date', [$from, $to]);
        $vouchers = $q->orderBy($filters['sort'] ?? 'entry_date', $filters['direction'] ?? 'desc')->orderBy('id')->paginate(15)->withQueryString()->through(function ($row) use ($association) {
            $row->billing = DB::table('association_bill_documents')->where('voucher_id', $row->id)->exists();
            $row->lines = DB::table('association_finance_lines as l')->join('association_finance_accounts as a', 'a.id', '=', 'l.account_id')->where('l.voucher_id', $row->id)->select('l.id', 'l.account_id', 'a.code', 'a.name', 'a.is_money', 'l.debit_minor', 'l.credit_minor', 'l.cleared_on', 'l.statement_reference')->orderBy('l.id')->get();
            $row->events = DB::table('association_finance_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_id')->where('e.voucher_id', $row->id)->orderBy('e.id')->get(['e.action', 'e.note', 'e.created_at', 'u.name']);
            $row->evidence_url = $row->evidence_path ? "/association-finance/$association/vouchers/$row->id/evidence" : null;
            unset($row->evidence_path);

            return $row;
        });
        $opening = ! empty($filters['account']) ? Finance::totals($association, CarbonImmutable::parse($from)->subDay()->toDateString(), account: $filters['account']) : collect();
        $openingMinor = $opening->sum(fn ($row) => (int) $row->debit_minor - (int) $row->credit_minor);
        $cashBookQuery = DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->join('association_finance_accounts as a', 'a.id', '=', 'l.account_id')->where('v.association_id', $association)->where('a.is_money', true)->whereIn('v.status', ['posted', 'reversed'])->where('v.entry_date', '<=', $to);
        $cashBook = (int) $cashBookQuery->sum(DB::raw('l.debit_minor - l.credit_minor'));
        $cashCleared = (int) (clone $cashBookQuery)->whereNotNull('l.cleared_on')->where('l.cleared_on', '<=', $to)->sum(DB::raw('l.debit_minor - l.credit_minor'));
        $remainingBill = 'filmunity_bill_amount(d.voucher_id) - COALESCE((SELECT sum(amount) FROM association_bill_allocations WHERE document_id=d.voucher_id AND voided_at IS NULL),0)';
        $pendingActions = ['review' => DB::table('association_finance_vouchers')->where('association_id', $association)->whereIn('status', ['draft', 'submitted'])->count(), 'overdue' => DB::table('association_bill_documents as d')->join('association_finance_vouchers as v', 'v.id', '=', 'd.voucher_id')->where('d.association_id', $association)->where('d.flow', 'invoice')->where('v.status', 'posted')->whereDate('d.due_date', '<', today())->whereRaw("($remainingBill)>0")->count(), 'advances' => DB::table('association_bill_documents as d')->join('association_finance_vouchers as v', 'v.id', '=', 'd.voucher_id')->where('d.association_id', $association)->whereIn('d.flow', ['receipt', 'payment'])->where('v.status', 'posted')->whereRaw("($remainingBill)>0")->count()];
        $associationRecord = AssociationWorkspace::forAdmin($request->user())->where('id', $association)->first(['id', 'name']);
        $branding = DB::table('association_workspace_settings')->where('association_id', $association)->first(['display_name', 'logo']);
        $ledger = null;
        if (! empty($filters['account'])) {
            abort_unless($accounts->contains('id', $filters['account']), 404);
            $ledger = DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->where('v.association_id', $association)->where('l.account_id', $filters['account'])->whereIn('v.status', ['posted', 'reversed'])->whereBetween('v.entry_date', [$from, $to])->select('l.*', 'v.number', 'v.entry_date', 'v.description', 'v.kind')->selectRaw('(? + SUM(l.debit_minor - l.credit_minor) OVER (ORDER BY v.entry_date, l.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)) AS running_minor', [$openingMinor])->orderBy('v.entry_date')->orderBy('l.id')->paginate(30, ['*'], 'ledger_page')->withQueryString();
        }
        $cleared = null;
        if (! empty($filters['account'])) {
            $cleared = DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->where('v.association_id', $association)->where('l.account_id', $filters['account'])->whereIn('v.status', ['posted', 'reversed'])->whereNotNull('l.cleared_on')->where('l.cleared_on', '<=', $to)->where('v.entry_date', '<=', $to)->sum(DB::raw('l.debit_minor - l.credit_minor'));
        }
        return Inertia::render('association-finance', ['association' => $associationRecord, 'associationBranding' => ['display_name' => $branding?->display_name ?: $associationRecord?->name, 'logo_url' => $branding?->logo ? route('membership-applications.logo', ['association' => $association], false) : null], 'associations' => AssociationWorkspace::forAdmin($request->user())->orderBy('name')->get(['id', 'name']), 'settings' => $settings, 'accounts' => $accounts, 'vouchers' => $vouchers, 'trial' => Finance::totals($association, $to), 'movement' => Finance::totals($association, $to, $from), 'ledger' => $ledger, 'opening' => $opening, 'clearedBalance' => $cleared, 'cashSummary' => ['book' => $cashBook, 'cleared' => $cashCleared], 'pendingActions' => $pendingActions, 'filters' => [...$filters, 'from' => $from, 'to' => $to]]);
    }

    public function initialize(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['starts_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        Finance::setup($association, $request->user(), $data['starts_on']);

        return back()->with('status', 'Finance initialized. Review receipt classifications before enabling collection posting.');
    }

    public function settings(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        if ($request->has('financial_year_from')) {
            $data = $request->validate(['financial_year_from' => ['required', 'date_format:Y-m-d'], 'financial_year_to' => ['required', 'date_format:Y-m-d'], 'books_beginning_from' => ['required', 'date_format:Y-m-d'], 'voucher_numbering_mode' => ['required', Rule::in(['automatic', 'manual'])]]);
            DB::transaction(function () use ($request, $association, $data): void {
                Finance::lock($association); Finance::check(Finance::settings($association) !== null, 'Initialize finance first.');
                Finance::check($data['financial_year_to'] === CarbonImmutable::parse($data['financial_year_from'])->addYear()->subDay()->toDateString(), 'Financial year end must be one year minus one day after the start.');
                Finance::check($data['books_beginning_from'] >= $data['financial_year_from'], 'Books beginning cannot precede the financial year.');
                DB::table('association_finance_settings')->where('association_id', $association)->update([...$data, 'starts_on' => $data['books_beginning_from'], 'updated_at' => now()]);
                Finance::event($association, $request->user(), 'books_configuration_updated', note: json_encode($data, JSON_THROW_ON_ERROR));
            });
            return back()->with('status', 'Financial year and books configuration saved.');
        }
        $data = $request->validate(['yearly_account_id' => ['required', 'uuid'], 'lifetime_account_id' => ['required', 'uuid'], 'policy_note' => ['required', 'string', 'max:2000'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $association, $data): void {
            Finance::lock($association);
            Finance::check(Finance::settings($association) !== null, 'Initialize finance first.');
            foreach (['yearly_account_id', 'lifetime_account_id'] as $key) {
                $a = Finance::account($association, $data[$key]);
                Finance::check(in_array($a->type, ['income', 'fund', 'liability'], true), 'Receipt mappings must use an income, fund or liability account.');
            }DB::table('association_finance_settings')->where('association_id', $association)->update(['yearly_account_id' => $data['yearly_account_id'], 'lifetime_account_id' => $data['lifetime_account_id'], 'policy_note' => $data['policy_note'], 'enabled' => true, 'updated_at' => now()]);
            Finance::event($association, $request->user(), 'policy_updated', note: json_encode($data, JSON_THROW_ON_ERROR));
        });

        return back()->with('status', 'Membership receipt classifications saved. Existing entries are unchanged.');
    }

    public function account(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z0-9_-]{1,20}$/D'], 'name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::in(['asset', 'liability', 'fund', 'income', 'expense'])], 'is_money' => ['required', 'boolean'], 'group_id' => ['nullable', 'uuid'], 'opening_balance' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'], 'opening_side' => ['nullable', Rule::in(['DR', 'CR'])]]);
        DB::transaction(function () use ($request, $association, $data): void {
            Finance::lock($association);
            Finance::check(Finance::settings($association) !== null, 'Initialize finance first.');
            Finance::check(! $data['is_money'] || $data['type'] === 'asset', 'Cash/bank accounts must be assets.');
            Finance::check(! DB::table('association_finance_accounts')->where('association_id', $association)->where('code', $data['code'])->exists(), 'Account code is already in use.');
            $group = $data['group_id'] ?? AccountingMasters::classification($association, $data['type'], (bool) $data['is_money']);
            Finance::check(DB::table('association_account_groups')->where('association_id', $association)->where('id', $group)->exists(), 'Choose a group belonging to this association.');
            DB::table('association_finance_accounts')->insert(['id' => (string) Str::uuid(), 'association_id' => $association, 'code' => $data['code'], 'name' => $data['name'], 'type' => $data['type'], 'is_money' => $data['is_money'], 'group_id' => $group, 'opening_balance_minor' => (int) round(((float) ($data['opening_balance'] ?? '0')) * 100), 'opening_side' => $data['opening_side'] ?? 'DR', 'created_at' => now(), 'updated_at' => now()]);
            Finance::event($association, $request->user(), 'account_created', note: $data['code'].' '.$data['name']);
        });

        return back()->with('status', 'Account created.');
    }

    private function voucherData(Request $request): array
    {
        return $request->validate(['entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'kind' => ['required', Rule::in(['receipt', 'payment', 'transfer', 'journal', 'opening'])], 'description' => ['required', 'string', 'max:2000'], 'party' => ['nullable', 'string', 'max:160'], 'reference' => ['nullable', 'string', 'max:160'], 'lines' => ['required', 'array', 'list', 'min:2', 'max:40'], 'lines.*' => ['required', 'array:account_id,debit,credit'], 'lines.*.account_id' => ['required', 'uuid'], 'lines.*.debit' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'], 'lines.*.credit' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'], 'evidence' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'extensions:pdf,png,jpg,jpeg', 'max:5120']]);
    }

    public function save(Request $request, string $association, ?string $voucher = null)
    {
        Finance::owner($request->user(), $association);
        $data = $this->voucherData($request);
        $data = [...$data, ...$request->validate(['instrument_mode' => ['nullable', Rule::in(['UPI / NEFT / RTGS', 'Cheque', 'Card', 'Cash'])], 'instrument_reference' => ['nullable', 'string', 'max:120'], 'instrument_date' => ['nullable', 'date_format:Y-m-d'], 'allocation_reference_type' => ['nullable', Rule::in(['against_ref', 'advance', 'on_account'])], 'allocation_target_id' => ['nullable', 'uuid']])];
        $path = $request->file('evidence')?->store('association-finance-evidence', 'local');
        Finance::check($path !== false, 'Evidence could not be stored.');
        $oldPath = null;
        try {
            DB::transaction(function () use ($request, $association, $voucher, $data, $path, &$oldPath): void {
                Finance::lock($association);
                $lines = Finance::lines($association, $data['lines'], $data['kind']);
                $settings = Finance::settings($association);
                Finance::check($settings !== null, 'Initialize finance first.');
                Finance::editableDate($settings, $data['entry_date']);
                if ($data['kind'] === 'opening') {
                    Finance::check($data['entry_date'] === $settings->starts_on, 'Opening balances must use the books start date.');
                }
                if ($voucher) {
                    Finance::check(! DB::table('association_bill_documents')->where('voucher_id', $voucher)->where('association_id', $association)->exists(), 'Edit this draft from Invoices and settlements.');
                    $row = DB::table('association_finance_vouchers')->where('association_id', $association)->where('id', $voucher)->lockForUpdate()->first();
                    abort_if(! $row, 404);
                    Finance::check($row->status === 'draft', 'Only drafts can be edited.');
                    if (! str_starts_with($row->number, 'FIN-')) {
                        $fy = fn ($date) => (int) substr($date, 0, 4) - ((int) substr($date, 5, 2) < 4 ? 1 : 0);
                        Finance::check($row->kind === $data['kind'] && $fy($row->entry_date) === $fy($data['entry_date']), 'A numbered draft keeps its voucher type and financial year. Create a new voucher for another type or year.');
                    }$oldPath = $row->evidence_path;
                    DB::table('association_finance_vouchers')->where('id', $voucher)->update(['entry_date' => $data['entry_date'], 'kind' => $data['kind'], 'description' => $data['description'], 'party' => $data['party'] ?? null, 'reference' => $data['reference'] ?? null, 'instrument_mode' => $data['instrument_mode'] ?? null, 'instrument_reference' => $data['instrument_reference'] ?? null, 'instrument_date' => $data['instrument_date'] ?? null, 'allocation_reference_type' => $data['allocation_reference_type'] ?? null, 'allocation_target_id' => $data['allocation_target_id'] ?? null, 'evidence_path' => $path ?? $oldPath, 'updated_at' => now()]);
                    DB::table('association_finance_lines')->where('voucher_id', $voucher)->delete();
                    foreach ($lines as $line) {
                        DB::table('association_finance_lines')->insert(['voucher_id' => $voucher, ...$line]);
                    }Finance::event($association, $request->user(), 'draft_updated', $voucher);
                } else {
                    $createdVoucher = Finance::insert($association, $request->user(), [...$data, 'evidence_path' => $path], $lines);
                    if (in_array($data['kind'], ['receipt', 'payment'], true) && ($data['allocation_target_id'] ?? null)) {
                        $party = DB::table('association_finance_accounts')->where('association_id', $association)->whereIn('id', array_column($data['lines'], 'account_id'))->whereNotNull($data['kind'] === 'receipt' ? 'membership_id' : 'vendor_id')->first();
                        Finance::check($party !== null, 'Select a member or vendor ledger before choosing an allocation reference.');
                        DB::table('association_bill_documents')->insert(['voucher_id' => $createdVoucher, 'association_id' => $association, 'party_account_id' => $party->id, 'flow' => $data['kind'], 'client_token' => (string) Str::uuid(), 'created_at' => now()]);
                    }
                }
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
        if ($path && $oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

return back()->with('status', 'Financial draft saved. Submit it for review before posting.');
    }

    public function decide(Request $request, string $association, string $voucher)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['action' => ['required', Rule::in(['submit', 'post', 'reject', 'reverse'])], 'note' => ['required', 'string', 'max:2000'], 'entry_date' => ['required_if:action,reverse', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'confirmed' => ['required', 'accepted']]);
        Finance::decide($association, $voucher, $request->user(), $data['action'], $data['note'], $data['entry_date'] ?? null);

        return back()->with('status', 'Financial record updated.');
    }

    public function sync(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $settings = Finance::settings($association);
        Finance::check($settings !== null && $settings->enabled, 'Enable receipt classifications first.');
        $count = 0;
        $skipped = 0;
        // Bounded batches: repeat safely until none remain. Old closed-period receipts are reported, not silently redated.
        $cutover = CarbonImmutable::parse($settings->starts_on, config('app.timezone', 'UTC'))->utc();
        $openAfter = $settings->locked_through ? CarbonImmutable::parse($settings->locked_through, config('app.timezone', 'UTC'))->addDay()->utc() : $cutover;
        $missing = DB::table('association_payment_receipts as r')->join('association_payment_requests as p', 'p.id', '=', 'r.payment_id')->where('r.association_id', $association)->where('p.status', 'acknowledged')->where('p.amount_minor', '>', 0)->where('r.issued_at', '>=', $cutover->toIso8601String())->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('association_finance_vouchers as v')->whereColumn('v.source_id', 'r.id')->where('v.source_type', 'membership_receipt')->whereColumn('v.association_id', 'r.association_id');
        });
        $skipped = (clone $missing)->where('r.issued_at', '<', $openAfter->toIso8601String())->count();
        $ids = (clone $missing)->where('r.issued_at', '>=', $openAfter->toIso8601String())->orderBy('r.issued_at')->orderBy('r.id')->limit(100)->pluck('r.id');
        foreach ($ids as $id) {
            DB::transaction(fn () => Finance::collect($id, $request->user()));
            $count++;
        }

        return back()->with('status', "Posted $count membership receipts. $skipped closed-period receipts need accountant review. Repeat to process additional batches.");
    }

    public function clear(Request $request, string $association, string $line)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['cleared_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'statement_reference' => ['required', 'string', 'max:160']]);
        DB::transaction(function () use ($request, $association, $line, $data): void {
            Finance::lock($association);
            $row = DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->join('association_finance_accounts as a', 'a.id', '=', 'l.account_id')->where('v.association_id', $association)->where('l.id', $line)->first(['l.*', 'v.status', 'v.entry_date', 'a.is_money']);
            abort_if(! $row, 404);
            Finance::check($row->is_money && in_array($row->status, ['posted', 'reversed'], true), 'Only posted cash/bank lines can be reconciled.');
            $s = Finance::settings($association);
            Finance::editableDate($s, $row->entry_date);
            if ($data['cleared_on']) {
                Finance::check($data['cleared_on'] >= $row->entry_date, 'Clearance cannot precede the book date.');
                Finance::editableDate($s, $data['cleared_on']);
            }DB::table('association_finance_lines')->where('id', $line)->update($data);
            Finance::event($association, $request->user(), 'clearance_updated', $row->voucher_id, json_encode(['line' => $line, ...$data], JSON_THROW_ON_ERROR));
        });

        return back()->with('status', 'Cash/bank clearance recorded.');
    }

    public function close(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['locked_through' => ['required', 'date_format:Y-m-d', 'before:today'], 'note' => ['required', 'string', 'max:2000'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $association, $data): void {
            Finance::lock($association);
            $s = Finance::settings($association);
            Finance::check($s !== null, 'Initialize finance first.');
            Finance::editableDate($s, $data['locked_through']);
            Finance::check(! DB::table('association_finance_vouchers')->where('association_id', $association)->whereIn('status', ['draft', 'submitted'])->where('entry_date', '<=', $data['locked_through'])->exists(), 'Resolve drafts and submitted vouchers before closing this period.');
            $missing = DB::table('association_payment_receipts as r')->join('association_payment_requests as p', 'p.id', '=', 'r.payment_id')->where('r.association_id', $association)->where('p.status', 'acknowledged')->where('p.amount_minor', '>', 0)->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('association_finance_vouchers as v')->whereColumn('v.source_id', 'r.id')->where('v.source_type', 'membership_receipt')->whereColumn('v.association_id', 'r.association_id');
            })->pluck('r.issued_at')->contains(fn ($d) => ($day = CarbonImmutable::parse($d)->setTimezone(config('app.timezone', 'UTC'))->toDateString()) >= $s->starts_on && $day <= $data['locked_through']);
            Finance::check(! $missing, 'Post all acknowledged receipts in this period before closing.');
            DB::table('association_finance_settings')->where('association_id', $association)->update(['locked_through' => $data['locked_through'], 'updated_at' => now()]);
            Finance::event($association, $request->user(), 'period_closed', note: $data['locked_through'].' '.$data['note']);
        });

        return back()->with('status', 'Books locked through the selected date. Use open-period corrections for subsequent adjustments.');
    }

    public function evidence(Request $request, string $association, string $voucher)
    {
        Finance::owner($request->user(), $association);
        $path = DB::table('association_finance_vouchers')->where('association_id', $association)->where('id', $voucher)->value('evidence_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'finance-evidence.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['dataset' => ['required', Rule::in(['accounts', 'vouchers', 'lines', 'events', 'settings', 'groups', 'vendors', 'counters', 'bill_documents', 'bill_allocations', 'notes', 'tax_settings', 'tax_documents', 'tax_counters', 'tax_items', 'bundle'])], 'account' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $dataset = $data['dataset'];
        if ($dataset === 'bundle') {
            return response()->streamDownload(function () use ($association): void {
                DB::transaction(function () use ($association): void {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                    echo '{"schema":"filmunity-association-finance/v6","currency":"INR","association_id":'.json_encode($association).',"timezone":'.json_encode(config('app.timezone', 'UTC')).',"exported_at":'.json_encode(now()->toIso8601String());
                    foreach (['settings', 'accounts', 'vouchers', 'lines', 'events', 'groups', 'vendors', 'counters', 'bill_documents', 'bill_allocations', 'notes', 'tax_settings', 'tax_documents', 'tax_counters', 'tax_items'] as $set) {
                        echo ','.json_encode($set).':[';
                        $first = true;
                        if ($set === 'lines') {
                            $query = DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->where('v.association_id', $association)->select('l.*')->orderBy('l.id');
                        } else {
                            $query = DB::table(match ($set) {
                                'bill_documents' => 'association_bill_documents','bill_allocations' => 'association_bill_allocations','groups' => 'association_account_groups','vendors' => 'association_finance_vendors','counters' => 'association_voucher_counters',default => 'association_finance_'.$set
                            })->where('association_id', $association)->orderBy((in_array($set, ['bill_documents', 'notes', 'tax_documents'], true) ? 'voucher_id' : (in_array($set, ['settings', 'counters', 'tax_settings', 'tax_counters'], true) ? 'association_id' : 'id')));
                        }
                        foreach ($query->cursor() as $row) {
                            if (! $first) {
                                echo ',';
                            }$values = (array) $row;
                            if ($set === 'vouchers') {
                                $values['evidence_url'] = $values['evidence_path'] ? '/association-finance/'.$association.'/vouchers/'.$values['id'].'/evidence' : null;
                                unset($values['evidence_path']);
                            }echo json_encode($values, JSON_THROW_ON_ERROR);
                            $first = false;
                        }echo ']';
                    }echo '}';
                });
            }, 'filmunity-finance-'.$association.'.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
        }

        // Stream one consistent PostgreSQL snapshot; CSV values are transport-safe, not an import authorization.
        return response()->streamDownload(function () use ($association, $dataset, $data): void {
            DB::transaction(function () use ($association, $dataset, $data): void {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                if ($dataset === 'lines') {
                    $query = DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->where('v.association_id', $association)->select('l.*');
                } else {
                    $query = DB::table(match ($dataset) {
                        'bill_documents' => 'association_bill_documents','bill_allocations' => 'association_bill_allocations','groups' => 'association_account_groups','vendors' => 'association_finance_vendors','counters' => 'association_voucher_counters',default => 'association_finance_'.$dataset
                    })->where('association_id',$association);
                }
                if ($dataset === 'vouchers') {
                    $query->select('id','association_id','number','entry_date','kind','status','description','party','reference','source_type','source_id','reversal_of','created_by','posted_by','posted_at','decision_note','created_at','updated_at');
                }
                if ($dataset === 'lines') {
                    if (! empty($data['account'])) $query->where('l.account_id', $data['account']);
                    if (! empty($data['from'])) $query->whereDate('v.entry_date', '>=', $data['from']);
                    if (! empty($data['to'])) $query->whereDate('v.entry_date', '<=', $data['to']);
                    $query->addSelect('v.number', 'v.entry_date', 'v.description');
                }
                $query->orderBy(in_array($dataset,['bill_documents', 'notes', 'tax_documents'],true) ? 'voucher_id' : (in_array($dataset,['settings', 'counters', 'tax_settings', 'tax_counters'],true) ? 'association_id' : ($dataset === 'lines' ? 'l.id' : 'id')));
                $handle = fopen('php://output','w');
                $header = false;
                foreach ($query->cursor() as $row) {
                    $values = (array) $row;
                    if (! $header) {
                        fputcsv($handle,array_keys($values),',','"','');
                        $header = true;
                    }fputcsv($handle,array_map(fn ($value) => is_string($value) && preg_match('/^[\s]*[=+@-]/u',$value) ? "'".$value : $value,array_values($values)),',','"','');
                }fclose($handle);
            });
        }, 'filmunity-finance-'.$dataset.'-'.$association.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
