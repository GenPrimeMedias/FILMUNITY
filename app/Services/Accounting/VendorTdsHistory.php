<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

final class VendorTdsHistory
{
    public static function report(string $association, string $vendor, string $from, string $to): array
    {
        $movements = DB::select(<<<'SQL'
WITH sources AS (
 SELECT v.id, v.number, v.entry_date, v.status, t.voucher_id AS original_id,
  v.number AS original_number, 'bill' AS kind, t.tds::numeric AS amount,
  t.snapshot->>'tds_reference' AS legal_reference
 FROM association_finance_tax_documents t
 JOIN association_finance_vouchers v ON v.id=t.voucher_id AND v.association_id=t.association_id
 JOIN association_bill_documents d ON d.voucher_id=v.id AND d.association_id=v.association_id
 JOIN association_finance_accounts a ON a.id=d.party_account_id AND a.association_id=v.association_id
 WHERE v.association_id=? AND a.vendor_id=? AND v.kind='bill'
 UNION ALL
 SELECT v.id,v.number,v.entry_date,v.status,n.original_document_id,o.number,'reviewed_adjustment',
  -(n.tax_correction->>'tds')::numeric,t.snapshot->>'tds_reference'
 FROM association_finance_notes n
 JOIN association_finance_vouchers v ON v.id=n.voucher_id AND v.association_id=n.association_id
 JOIN association_finance_vouchers o ON o.id=n.original_document_id AND o.association_id=n.association_id
 JOIN association_finance_tax_documents t ON t.voucher_id=o.id AND t.association_id=n.association_id
 JOIN association_bill_documents d ON d.voucher_id=o.id AND d.association_id=n.association_id
 JOIN association_finance_accounts a ON a.id=d.party_account_id AND a.association_id=n.association_id
 WHERE n.association_id=? AND a.vendor_id=? AND n.tax_correction IS NOT NULL
), movements AS (
 SELECT s.id,s.number,s.entry_date,s.original_id,s.original_number,s.kind,s.amount,s.legal_reference
 FROM sources s WHERE s.status IN ('posted','reversed')
 UNION ALL
 SELECT r.id,r.number,r.entry_date,s.original_id,s.original_number,
  CASE WHEN s.kind='bill' THEN 'bill_reversal' ELSE 'adjustment_reversal' END,-s.amount,s.legal_reference
 FROM sources s JOIN association_finance_vouchers r ON r.reversal_of=s.id AND r.association_id=? AND r.status='posted'
 WHERE s.status IN ('posted','reversed')
)
SELECT id,number,entry_date,original_id,original_number,kind,amount::numeric(24,2)::text AS amount,legal_reference
FROM movements WHERE entry_date BETWEEN ?::date AND ?::date ORDER BY entry_date,number,id
SQL, [$association, $vendor, $association, $vendor, $association, $from, $to]);
        $totals = ['original_deductions' => '0.00', 'reviewed_adjustments' => '0.00', 'reversal_movements' => '0.00', 'net_deducted' => '0.00'];
        foreach ($movements as $movement) {
            $key = match ($movement->kind) {
                'bill' => 'original_deductions', 'reviewed_adjustment' => 'reviewed_adjustments', default => 'reversal_movements'
            };
            $totals[$key] = bcadd($totals[$key], $movement->amount, 2);
            $totals['net_deducted'] = bcadd($totals['net_deducted'], $movement->amount, 2);
        }
        // A challan can cover several vendors. Show only this vendor's active matched
        // share, never subtract the whole remittance from vendor deductions.
        $payments = DB::table('association_tds_matches as m')
            ->join('association_tds_challans as c', 'c.id', '=', 'm.challan_id')
            ->join('association_finance_lines as l', 'l.id', '=', 'c.remittance_line_id')
            ->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')
            ->join('association_bill_documents as d', 'd.voucher_id', '=', 'm.document_id')
            ->join('association_finance_accounts as a', 'a.id', '=', 'd.party_account_id')
            ->where('m.association_id', $association)->where('c.association_id', $association)->where('v.association_id', $association)
            ->where('d.association_id', $association)->where('a.association_id', $association)->where('a.vendor_id', $vendor)
            ->whereNull('m.released_at')->where('v.status', 'posted')->whereBetween('c.paid_on', [$from, $to])
            ->groupBy('c.id', 'c.reference', 'c.paid_on', 'c.amount_minor', 'v.id', 'v.number')
            ->select('c.id', 'c.reference', 'c.paid_on', 'c.amount_minor', 'v.id as payment_id', 'v.number as payment_number')
            ->selectRaw('sum(m.amount_minor)::text as vendor_matched_minor')->orderBy('c.paid_on')->get();

        return ['from' => $from, 'to' => $to, 'movements' => $movements, 'totals' => $totals, 'payments' => $payments];
    }
}
