<?php

namespace App\Http\Controllers;

use App\Services\Accounting\RecurringMemberInvoices as Templates;
use App\Support\AssociationFinance as Finance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class RecurringMemberInvoiceController extends Controller
{
    public function index(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);

        return Inertia::render('recurring-member-invoices', [
            'association' => $association,
            'activity' => DB::table('association_finance_events')->where('association_id', $association)->whereIn('action', ['recurring_scheduled_outcome', 'recurring_generation_run', 'invoice_template_paused', 'invoice_template_resumed'])->orderByDesc('created_at')->limit(20)->get(['action', 'actor_id', 'created_at', 'note']),
            'accounts' => DB::table('association_finance_accounts')->where('association_id', $association)->where('active', true)->get(['id', 'name', 'type', 'is_money', 'membership_id', 'vendor_id', 'code']),
            'templates' => DB::table('association_invoice_templates')->where('association_id', $association)->orderByDesc('created_at')->get()->map(function ($row) {
                $row->items = json_decode($row->items, true, flags: JSON_THROW_ON_ERROR);
                $row->next_billing_date = Templates::date($row->next_period, $row->anchor_day);

                return $row;
            }),
            'runs' => DB::table('association_invoice_template_runs as r')->join('association_finance_vouchers as v', 'v.id', '=', 'r.voucher_id')->where('r.association_id', $association)->orderByDesc('r.created_at')->limit(50)->get(['r.*', 'v.number', 'v.status'])->map(function ($row) {
                $row->snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);

                return $row;
            }),
        ]);
    }

    public function save(Request $request, string $association, ?string $template = null)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'party_account_id' => [$template ? 'nullable' : 'required', 'uuid'],
            'starts_on' => [$template ? 'nullable' : 'required', 'date_format:Y-m-d'], 'due_days' => ['required', 'integer', 'between:0,90'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:20'], 'items.*.counter_account_id' => ['required', 'uuid'],
            'items.*.description' => ['required', 'string', 'max:500'], 'items.*.amount' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D']]);
        Templates::save($association, $request->user(), $data, $template);

        return back()->with('status', 'Template saved. Existing invoice drafts are unchanged.');
    }

    public function state(Request $request, string $association, string $template)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        Templates::pause($association, $request->user(), $template, (bool) $data['paused']);

        return back()->with('status', $data['paused'] ? 'Template paused.' : 'Template resumed. Past missed periods were skipped.');
    }

    public function generate(Request $request, string $association)
    {
        $count = Templates::generate($association, $request->user());

        return back()->with('status', "$count invoice drafts generated. Review and post separately.");
    }
}
