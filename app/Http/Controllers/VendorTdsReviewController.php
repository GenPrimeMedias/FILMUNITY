<?php

namespace App\Http\Controllers;

use App\Services\Accounting\VendorTdsReview;
use App\Support\AssociationFinance as Finance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

final class VendorTdsReviewController extends Controller
{
    public function index(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);

        return Inertia::render('vendor-tds-reviews', ['association' => $association,
            'vendors' => DB::table('association_finance_vendors')->where('association_id', $association)->where('active', true)->get(['id', 'name', 'reference']),
            'reviews' => VendorTdsReview::visible($association),
        ]);
    }

    public function evidence(Request $request, string $association, string $review)
    {
        Finance::owner($request->user(), $association);
        $path = DB::table('association_vendor_tds_reviews')->where('association_id', $association)->where('id', $review)->value('evidence_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'vendor-tds-evidence.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function store(Request $request, string $association)
    {
        Finance::owner($request->user(), $association);
        $data = $request->validate([
            'threshold_amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'threshold_basis' => ['nullable', 'in:pre_gst_vendor_bills'], 'threshold_period' => ['nullable', 'in:april_march'],
            'threshold_accountant_reviewed' => ['sometimes', 'boolean'], 'threshold_history_reviewed' => ['sometimes', 'boolean'],
            'certificate_reference' => ['nullable', 'required_with:valid_from,valid_until', 'string', 'max:160'],
            'valid_from' => ['nullable', 'required_with:certificate_reference', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'required_with:certificate_reference', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'extensions:pdf,png,jpg,jpeg', 'max:5120'],
            'vendor_id' => ['required', 'uuid'], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'review_notes' => ['required', 'string', 'max:2000'], 'supporting_reference' => ['nullable', 'string', 'max:500'],
            'legal_reference' => ['required', 'string', 'max:100'], 'reviewed_rate' => ['required', 'numeric', 'decimal:0,2', 'between:0,40'],
        ]);
        $path = $request->file('evidence')?->store('association-finance-evidence', 'local');
        Finance::check($path !== false, 'Evidence could not be stored.');
        unset($data['evidence']);
        try {
            VendorTdsReview::record($association, $request->user(), [...$data, 'evidence_path' => $path]);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return back()->with('status', 'Vendor TDS review recorded. Bill entry still requires explicit review.');
    }
}
