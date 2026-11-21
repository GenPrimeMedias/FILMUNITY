<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

final class VendorTdsThreshold
{
    public static function report(string $association, string $vendor, string $date, string $draft): array
    {
        $year = (int) substr($date, 0, 4) - ((int) substr($date, 5, 2) < 4 ? 1 : 0);
        $from = "$year-04-01";
        $rule = VendorTdsReview::effective($association, $vendor, $date);
        $missing = [];
        if (! $rule) {
            $missing[] = 'No vendor review effective on the book date.';
        }
        if (! $rule?->threshold_amount) {
            $missing[] = 'No configured threshold amount.';
        }
        if ($rule?->threshold_basis !== 'pre_gst_vendor_bills' || $rule?->threshold_period !== 'april_march') {
            $missing[] = 'Accountant must select the supported pre-GST vendor-bill basis and April–March period.';
        }
        if (! $rule?->threshold_accountant_reviewed) {
            $missing[] = 'Accountant review of threshold applicability is not confirmed.';
        }
        if (! $rule?->threshold_history_reviewed) {
            $missing[] = 'Historical completeness, opening history, advances and excluded transactions have not been reviewed.';
        }
        $sources = DB::select(<<<'SQL'
WITH originals AS (
 SELECT v.id,v.number,v.entry_date,v.status,t.snapshot,t.taxable_base,t.exempt_base
 FROM association_finance_vouchers v
 JOIN association_bill_documents d ON d.voucher_id=v.id AND d.association_id=v.association_id
 JOIN association_finance_accounts a ON a.id=d.party_account_id AND a.association_id=v.association_id
 LEFT JOIN association_finance_tax_documents t ON t.voucher_id=v.id AND t.association_id=v.association_id
 WHERE v.association_id=? AND a.vendor_id=? AND v.kind='bill' AND v.status IN ('posted','reversed')
), events AS (
 SELECT id,number,entry_date,status,id AS original_id,'bill' AS kind,
  CASE WHEN snapshot IS NOT NULL THEN taxable_base+exempt_base END AS amount FROM originals
 UNION ALL
 SELECT v.id,v.number,v.entry_date,v.status,o.id,'correction',
  CASE WHEN n.tax_correction IS NOT NULL THEN -(SELECT sum((c->>'amount')::numeric) FROM jsonb_array_elements(n.tax_correction->'charges') c) END
 FROM originals o JOIN association_finance_notes n ON n.original_document_id=o.id AND n.association_id=?
 JOIN association_finance_vouchers v ON v.id=n.voucher_id AND v.association_id=n.association_id AND v.status IN ('posted','reversed')
), movements AS (
 SELECT id,number,entry_date,original_id,kind,amount FROM events
 UNION ALL
 SELECT r.id,r.number,r.entry_date,e.original_id,'reversal',-e.amount
 FROM events e JOIN association_finance_vouchers r ON r.reversal_of=e.id AND r.association_id=? AND r.status='posted'
)
SELECT id,number,entry_date,original_id,kind,amount::numeric(24,2)::text AS amount
FROM movements WHERE entry_date BETWEEN ?::date AND ?::date ORDER BY entry_date,number,id
SQL, [$association, $vendor, $association, $association, $from, $date]);
        $posted = '0.00';
        foreach ($sources as $source) {
            if ($source->amount === null) {
                $missing[] = "Missing frozen pre-GST amount for source {$source->number}.";
            } else {
                $posted = bcadd($posted, $source->amount, 2);
            }
        }
        $draft = AccountingMoney::decimal($draft);
        $projected = bcadd($posted, $draft, 2);
        $available = count($missing) === 0;
        $comparison = $available ? match (bccomp($projected, $rule->threshold_amount, 2)) {
            -1 => 'below', 0 => 'equal', 1 => 'above'
        } : 'unavailable';

        return ['from' => $from, 'to' => $date, 'available' => $available, 'missing' => $missing,
            'known_posted_total' => $posted, 'eligible_posted_total' => $available ? $posted : null,
            'draft_contribution' => $draft, 'projected_total' => $available ? $projected : null,
            'threshold' => $rule?->threshold_amount, 'comparison' => $comparison,
            'review' => $rule ? ['id' => $rule->id, 'effective_from' => $rule->effective_from, 'reviewed_by' => $rule->reviewed_by, 'notes' => $rule->review_notes] : null,
            'sources' => $sources];
    }
}
