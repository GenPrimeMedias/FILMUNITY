<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaxCorrectionService
{
    /** @param list<array{line_no: int|string, amount: int|float|string}> $charges */
    public static function plan(string $original, ?string $voucher, array $charges, string $tds, ?string $review): string
    {
        try {
            return DB::selectOne(
                'SELECT filmunity_tax_note_plan(?::uuid, ?::uuid, ?::jsonb, ?::numeric, ?)::text AS plan',
                [$original, $voucher, json_encode($charges, JSON_THROW_ON_ERROR), $tds, $review],
            )->plan;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23514') {
                throw $e;
            }

            $message = preg_replace('/^.*?ERROR:\s*([^\n]+).*$/s', '$1', $e->errorInfo[2] ?? '');
            throw ValidationException::withMessages(['charges' => trim($message) ?: 'The tax correction exceeds an original limit.']);
        }
    }

    public static function validate(string $original, string $voucher, string $snapshot): void
    {
        $reviewed = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        $current = self::plan($original, $voucher, $reviewed['charges'], (string) $reviewed['tds'], $reviewed['tds_review']);
        Finance::check(
            json_decode($current, true, flags: JSON_THROW_ON_ERROR) == $reviewed,
            'The available tax or charge amounts changed. Reject and recreate this note for review.',
        );
    }

    /** @return array<string, \stdClass> */
    public static function sources(string $association): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT t.voucher_id, t.serial, t.mode, t.tds::text,
                   (t.tds-coalesce(prior.tds,0))::text AS tds_available,
                   coalesce(items.charges,'[]'::jsonb)::text AS charges
            FROM association_finance_tax_documents t
            JOIN association_finance_vouchers v ON v.id=t.voucher_id AND v.status='posted'
            LEFT JOIN LATERAL (
              SELECT sum((n.tax_correction->>'tds')::numeric) AS tds
              FROM association_finance_notes n JOIN association_finance_vouchers nv ON nv.id=n.voucher_id
              WHERE n.original_document_id=t.voucher_id AND nv.status='posted'
            ) prior ON true
            LEFT JOIN LATERAL (
              SELECT jsonb_agg(jsonb_build_object('line_no',c.line_no,'description',c.description,'treatment',c.treatment,
                       'gst_rate',c.gst_rate::text,'remaining',(c.amount-coalesce(u.amount,0))::text)
                       ORDER BY c.line_no) AS charges
              FROM filmunity_tax_note_charges(t.voucher_id) c
              LEFT JOIN filmunity_tax_note_used(t.voucher_id,null) u ON u.line_no=c.line_no
            ) items ON true
            WHERE t.association_id=?
            SQL, [$association]))->mapWithKeys(function (\stdClass $row): array {
            $row->charges = json_decode($row->charges, true, flags: JSON_THROW_ON_ERROR);

            return [$row->voucher_id => $row];
        })->all();
    }
}
