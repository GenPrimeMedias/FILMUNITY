<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use stdClass;

final class TaxMovementReport
{
    /** @return list<stdClass> */
    public static function monthly(string $association, string $from, string $to): array
    {
        return DB::select(<<<'SQL'
            WITH sources AS (
                SELECT t.association_id, t.voucher_id, 1 AS direction, t.mode,
                    t.snapshot->>'tds_reference' AS tds_reference,
                    t.taxable_base, t.exempt_base, t.cgst, t.sgst, t.igst, t.tds_base, t.tds, t.gross, t.net
                FROM association_finance_tax_documents t
                WHERE t.association_id=?
                UNION ALL
                SELECT n.association_id, n.voucher_id, -1, t.mode,
                    t.snapshot->>'tds_reference',
                    charges.taxable_base, charges.exempt_base, charges.cgst, charges.sgst, charges.igst,
                    NULL::numeric, (n.tax_correction->>'tds')::numeric,
                    (n.tax_correction->>'gross')::numeric, (n.tax_correction->>'net')::numeric
                FROM association_finance_notes n
                JOIN association_finance_tax_documents t
                  ON t.voucher_id=n.original_document_id AND t.association_id=n.association_id
                CROSS JOIN LATERAL (
                    SELECT coalesce(sum((charge->>'amount')::numeric) FILTER (WHERE charge->>'treatment'='taxable'),0) AS taxable_base,
                        coalesce(sum((charge->>'amount')::numeric) FILTER (WHERE charge->>'treatment'<>'taxable'),0) AS exempt_base,
                        sum((charge->>'cgst')::numeric) AS cgst,
                        sum((charge->>'sgst')::numeric) AS sgst,
                        sum((charge->>'igst')::numeric) AS igst
                    FROM jsonb_array_elements(n.tax_correction->'charges') charge
                ) charges
                WHERE n.association_id=? AND n.tax_correction IS NOT NULL
            ), movements AS (
                SELECT s.*, v.kind, movement.entry_date, s.direction * movement.multiplier AS sign
                FROM sources s
                JOIN association_finance_vouchers v ON v.id=s.voucher_id AND v.association_id=s.association_id
                CROSS JOIN LATERAL (
                    SELECT v.entry_date, 1 AS multiplier
                    UNION ALL
                    SELECT r.entry_date, -1
                    FROM association_finance_vouchers r
                    WHERE r.reversal_of=v.id AND r.association_id=s.association_id AND r.status='posted'
                ) movement
                WHERE v.status IN ('posted','reversed')
                  AND movement.entry_date BETWEEN ?::date AND ?::date
            )
            SELECT to_char(entry_date,'YYYY-MM') AS month, kind, mode, tds_reference,
                sum(sign*taxable_base)::numeric(24,2)::text AS taxable_base,
                sum(sign*exempt_base)::numeric(24,2)::text AS exempt_base,
                sum(sign*cgst)::numeric(24,2)::text AS cgst,
                sum(sign*sgst)::numeric(24,2)::text AS sgst,
                sum(sign*igst)::numeric(24,2)::text AS igst,
                sum(sign*tds_base)::numeric(24,2)::text AS tds_base,
                sum(sign*tds)::numeric(24,2)::text AS tds,
                sum(sign*gross)::numeric(24,2)::text AS gross,
                sum(sign*net)::numeric(24,2)::text AS net
            FROM movements
            GROUP BY month, kind, mode, tds_reference
            ORDER BY month, kind, mode, tds_reference
            SQL, [$association, $association, $from, $to]);
    }
}
