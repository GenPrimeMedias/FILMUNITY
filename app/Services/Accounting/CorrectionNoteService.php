<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class CorrectionNoteService
{
    /** @return \stdClass&object{id: string, number: string, entry_date: string, party_account_id: string, name: string, taxed: bool, counter_account_id?: string} */
    public static function original(string $association, string $id, string $kind): object
    {
        $row = BillSettlementService::documents($association)->where('v.id', $id)->select('v.id', 'v.number', 'v.entry_date', 'v.status', 'd.flow', 'd.party_account_id', 'p.name')->first();
        Finance::check($row !== null && $row->status === 'posted' && (($kind === 'credit_note' && $row->flow === 'invoice') || ($kind === 'debit_note' && $row->flow === 'bill')), 'Choose a posted member invoice for a credit note or a posted vendor bill for a debit note.');
        $row->taxed = DB::table('association_finance_tax_documents')->where('association_id', $association)->where('voucher_id', $id)->exists();
        if ($row->taxed) {
            return $row;
        }
        $lines = DB::table('association_finance_lines')->where('voucher_id', $id)->get();
        $counter = $lines->first(fn ($l) => $l->account_id !== $row->party_account_id);
        Finance::check($lines->count() === 2 && $counter !== null, 'This original has multiple counter lines and needs accountant review.');
        $row->counter_account_id = $counter->account_id;

        return $row;
    }

    public static function available(string $association, string $original, ?string $except = null): string
    {
        $q = DB::table('association_finance_notes as n')->join('association_finance_vouchers as v', 'v.id', '=', 'n.voucher_id')->where('n.association_id', $association)->where('n.original_document_id', $original)->where('v.status', 'posted');
        if ($except) {
            $q->where('n.voucher_id', '<>', $except);
        }
        $used = (string) $q->selectRaw('COALESCE(SUM(filmunity_bill_amount(n.voucher_id)),0)::text AS amount')->first()->amount;
        $face = (string) DB::selectOne('SELECT filmunity_bill_amount(?)::text AS amount', [$original])->amount;

        return bcsub($face, $used, 2);
    }

    public static function validate(string $association, string $voucher): void
    {
        $note = DB::table('association_finance_notes as n')->join('association_finance_vouchers as v', 'v.id', '=', 'n.voucher_id')->where('n.association_id', $association)->where('n.voucher_id', $voucher)->first(['n.original_document_id', 'n.tax_correction', 'v.kind', 'v.entry_date']);
        Finance::check($note !== null, 'The correction note has no original reference.');
        $original = self::original($association, $note->original_document_id, $note->kind);
        Finance::check($note->entry_date >= $original->entry_date, 'A correction cannot predate its original.');
        if ($original->taxed) {
            Finance::check($note->tax_correction !== null, 'Review the original tax charges before posting.');
            TaxCorrectionService::validate($original->id, $voucher, $note->tax_correction);

            return;
        }
        $amount = (string) DB::selectOne('SELECT filmunity_bill_amount(?)::text AS amount', [$voucher])->amount;
        Finance::check(bccomp($amount, '0', 2) > 0 && bccomp($amount, self::available($association, $original->id, $voucher), 2) <= 0, 'Posted correction totals would exceed the original amount. Reject or revise this draft.');
    }

    /** @param array<string,mixed> $data */
    public static function save(string $association, Authenticatable $actor, array $data, ?string $voucher = null): string
    {
        return DB::transaction(function () use ($association, $actor, $data, $voucher): string {
            Finance::lock($association);
            $s = Finance::settings($association);
            Finance::check($s !== null, 'Initialize finance first.');
            Finance::editableDate($s, $data['entry_date']);
            if (! $voucher && ($existing = DB::table('association_bill_documents')->where('association_id', $association)->where('client_token', $data['client_token'])->value('voucher_id'))) {
                Finance::check(DB::table('association_finance_notes')->where('voucher_id', $existing)->exists(), 'Request token already belongs to another entry. Reload before retrying.');

                return $existing;
            }
            $original = self::original($association, $data['original_document_id'], $data['kind']);
            Finance::check($data['entry_date'] >= $original->entry_date, 'The note date cannot precede the original book date.');
            $snapshot = null;
            if ($original->taxed) {
                Finance::check(! empty($data['charges']), 'Choose original tax charges and enter the correction amounts.');
                $snapshot = TaxCorrectionService::plan($original->id, $voucher, $data['charges'], (string) ($data['tds_reversal'] ?? '0'), $data['tds_review'] ?? null);
                $plan = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
                $lines = Finance::lines($association, array_map(static fn (array $line): array => [
                    'account_id' => $line['account_id'],
                    'debit' => AccountingMoney::fromMinor((int) $line['debit_minor']),
                    'credit' => AccountingMoney::fromMinor((int) $line['credit_minor']),
                ], $plan['lines']), $data['kind']);
            } else {
                Finance::check(isset($data['amount']) && empty($data['charges']), 'Enter a correction amount for the original.');
                $amount = AccountingMoney::decimal($data['amount']);
                Finance::check(bccomp($amount, '0', 2) > 0 && bccomp($amount, self::available($association, $original->id, $voucher), 2) <= 0, 'Correction exceeds the amount still available against this original.');
                $dr = $data['kind'] === 'credit_note' ? $original->counter_account_id : $original->party_account_id;
                $cr = $dr === $original->party_account_id ? $original->counter_account_id : $original->party_account_id;
                $lines = Finance::lines($association, [['account_id' => $dr, 'debit' => $amount, 'credit' => '0'], ['account_id' => $cr, 'debit' => '0', 'credit' => $amount]], $data['kind']);
            }
            if ($voucher) {
                $old = DB::table('association_finance_notes as n')->join('association_finance_vouchers as v', 'v.id', '=', 'n.voucher_id')->where('n.association_id', $association)->where('n.voucher_id', $voucher)->first(['v.*', 'n.original_document_id']);
                abort_if(! $old, 404);
                Finance::check($old->status === 'draft' && $old->kind === $data['kind'] && $old->original_document_id === $original->id, 'Only a draft note with its existing type and original can be edited.');
                $fy = fn ($date) => (int) substr($date, 0, 4) - ((int) substr($date, 5, 2) < 4 ? 1 : 0);
                Finance::check($fy($old->entry_date) === $fy($data['entry_date']), 'Keep the note in its numbered financial year.');
                DB::table('association_finance_vouchers')->where('id', $voucher)->update(['entry_date' => $data['entry_date'], 'description' => $data['reason'], 'reference' => $data['reference'] ?? $original->number, 'evidence_path' => $data['evidence_path'] ?? $old->evidence_path, 'updated_at' => now()]);
                DB::table('association_finance_notes')->where('voucher_id', $voucher)->update(['reason' => $data['reason'], 'tax_correction' => $snapshot]);
                DB::table('association_finance_lines')->where('voucher_id', $voucher)->delete();
                foreach ($lines as $line) {
                    DB::table('association_finance_lines')->insert(['voucher_id' => $voucher, ...$line]);
                }
            } else {
                $voucher = Finance::insert($association, $actor, ['entry_date' => $data['entry_date'], 'kind' => $data['kind'], 'description' => $data['reason'], 'party' => $original->name, 'reference' => $data['reference'] ?? $original->number, 'evidence_path' => $data['evidence_path'] ?? null], $lines);
                DB::table('association_finance_notes')->insert(['voucher_id' => $voucher, 'association_id' => $association, 'original_document_id' => $original->id, 'reason' => $data['reason'], 'tax_correction' => $snapshot, 'created_at' => now()]);
                DB::table('association_bill_documents')->insert(['voucher_id' => $voucher, 'association_id' => $association, 'party_account_id' => $original->party_account_id, 'flow' => $data['kind'] === 'credit_note' ? 'receipt' : 'payment', 'client_token' => $data['client_token'], 'created_at' => now()]);
            }
            Finance::event($association,$actor,'correction_note_saved',$voucher,json_encode(['original' => $original->id, 'reason' => $data['reason'], 'tax_correction' => $plan ?? null],JSON_THROW_ON_ERROR));

            return $voucher;
        });
    }
}
