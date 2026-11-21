<?php

namespace App\Http\Controllers;

use App\Services\Accounting\BillingInvoiceReminders as Reminders;
use App\Services\Accounting\BillingReminderQueue;
use App\Support\AssociationFinance as Finance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class BillingInvoiceReminderController extends Controller
{
    public function index(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);

        return Inertia::render('billing-invoice-reminders', ['association' => $association, 'preview' => Reminders::preview($association),
            'reminders' => DB::table('association_invoice_reminders')->where('association_id', $association)->orderByDesc('created_at')->limit(100)->get()->map(function ($row) {
                $row->needs_review = BillingReminderQueue::needsReview($row);
                unset($row->snapshot, $row->queue_token);

                return $row;
            }),
            'activity' => DB::table('association_finance_events')->where('association_id', $association)->where('action', 'invoice_reminder_queue')->orderByDesc('id')->limit(100)->get(['id', 'created_at', 'note'])]);
    }

    public function store(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate(['voucher_id' => ['required', 'uuid'], 'confirmed' => ['accepted']]);
        $id = Reminders::request($association, $request->user(), $data['voucher_id']);
        BillingReminderQueue::enqueue($association, $request->user(), $id);

        return back()->with('status', 'Reminder queued for worker recheck. Live delivery remains disabled.');
    }

    public function retry(Request $request, string $association, string $reminder)
    {
        $request->validate(['confirmed' => ['accepted']]);
        BillingReminderQueue::enqueue($association, $request->user(), $reminder);

        return back()->with('status', 'Retry queued when eligible. Live delivery remains disabled.');
    }

    public function resolve(Request $request, string $association, string $reminder)
    {
        $data = $request->validate(['allow_retry' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000']]);
        BillingReminderQueue::resolve($association, $request->user(), $reminder, (bool) $data['allow_retry'], $data['reason']);

        return back()->with('status', 'Delivery outcome review recorded.');
    }
}
