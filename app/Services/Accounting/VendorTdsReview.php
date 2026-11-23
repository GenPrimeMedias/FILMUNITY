<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VendorTdsReview
{
    public static function visible(string $association)
    {
        return DB::table('association_vendor_tds_reviews')->where('association_id', $association)->orderByDesc('effective_from')->get()->map(function ($row) use ($association) {
            $row->evidence_url = $row->evidence_path ? "/association-finance/$association/tax/vendor-reviews/$row->id/evidence" : null;
            unset($row->evidence_path);

            return $row;
        });
    }

    public static function certificate(string $association, string $vendor, string $id, string $date): array
    {
        $review = DB::table('association_vendor_tds_reviews')->where('association_id', $association)->where('vendor_id', $vendor)->where('id', $id)->first();
        Finance::check($review !== null && $review->certificate_reference !== null && $review->effective_from <= $date && $review->valid_from <= $date && $review->valid_until >= $date, 'Choose a reviewed certificate valid for this vendor on the bill book date.');

        return ['profile_id' => $review->id, 'reference' => $review->certificate_reference, 'valid_from' => $review->valid_from, 'valid_until' => $review->valid_until, 'reviewed_rate' => $review->reviewed_rate, 'reviewed_by' => $review->reviewed_by, 'supporting_reference' => $review->supporting_reference];
    }

    public static function effective(string $association, string $vendor, string $date): ?object
    {
        return DB::table('association_vendor_tds_reviews')->where('association_id', $association)->where('vendor_id', $vendor)
            ->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    public static function record(string $association, Authenticatable $actor, array $data): string
    {
        Finance::owner($actor, $association);

        return DB::transaction(function () use ($association, $actor, $data) {
            Finance::lock($association);
            Finance::check(DB::table('association_finance_vendors')->where('association_id', $association)->where('id', $data['vendor_id'])->where('active', true)->exists(), 'Choose an active vendor in this association.');
            Finance::check(! DB::table('association_vendor_tds_reviews')->where('association_id', $association)->where('vendor_id', $data['vendor_id'])->where('effective_from', $data['effective_from'])->exists(), 'A review already exists for this effective date.');
            $id = (string) Str::uuid();
            $certificate = ! empty($data['certificate_reference']);
            Finance::check(! $certificate || (! empty($data['valid_from']) && ! empty($data['valid_until']) && $data['valid_until'] >= $data['valid_from']), 'Certificate requires an ordered validity period.');
            DB::table('association_vendor_tds_reviews')->insert(['id' => $id, 'association_id' => $association, 'vendor_id' => $data['vendor_id'], 'effective_from' => $data['effective_from'], 'review_notes' => $data['review_notes'], 'supporting_reference' => $data['supporting_reference'] ?? null, 'legal_reference' => $data['legal_reference'], 'reviewed_rate' => AccountingMoney::decimal($data['reviewed_rate']), 'reviewed_by' => $actor->getAuthIdentifier(), 'certificate_reference' => $certificate ? $data['certificate_reference'] : null, 'valid_from' => $certificate ? $data['valid_from'] : null, 'valid_until' => $certificate ? $data['valid_until'] : null, 'evidence_path' => $data['evidence_path'] ?? null, 'threshold_amount' => ! empty($data['threshold_amount']) ? AccountingMoney::decimal((string) $data['threshold_amount']) : null, 'threshold_basis' => $data['threshold_basis'] ?? null, 'threshold_period' => $data['threshold_period'] ?? null, 'threshold_accountant_reviewed' => $data['threshold_accountant_reviewed'] ?? false, 'threshold_history_reviewed' => $data['threshold_history_reviewed'] ?? false]);
            Finance::event($association, $actor, 'vendor_tds_review_recorded', note: json_encode(['review' => $id, 'vendor' => $data['vendor_id']], JSON_THROW_ON_ERROR));

            return $id;
        });
    }
}
