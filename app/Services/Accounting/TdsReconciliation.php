<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TdsReconciliation
{
    public static function liabilities(string $association): array
    {
        return DB::table('association_finance_tax_documents as t')
            ->join('association_finance_vouchers as v', 'v.id', '=', 't.voucher_id')
            ->join('association_finance_lines as l', 'l.voucher_id', '=', 'v.id')
            ->join('association_finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('v.association_id', $association)->where('v.kind', 'bill')->where('v.status', 'posted')
            ->where('a.code', 'like', 'TAX-TDS-%')->where('l.credit_minor', '>', 0)
            ->get(['v.id', 'v.number', 'v.party', 't.serial', 'a.id as account_id', 'a.name as account_name', 'l.credit_minor'])
            ->map(function ($row) use ($association) {
                $row->adjustments = DB::table('association_finance_notes as n')->join('association_finance_vouchers as v', 'v.id', '=', 'n.voucher_id')
                    ->join('association_finance_lines as l', 'l.voucher_id', '=', 'v.id')->where('n.association_id', $association)
                    ->where('n.original_document_id', $row->id)->where('v.status', 'posted')->where('l.account_id', $row->account_id)
                    ->get(['v.id', 'v.number', 'n.reason', 'l.debit_minor']);
                $row->adjusted_minor = (int) $row->credit_minor - (int) $row->adjustments->sum('debit_minor');
                $row->reconciled_minor = (int) DB::table('association_tds_matches')->where('association_id', $association)->where('document_id', $row->id)->whereNull('released_at')->sum('amount_minor');
                $row->outstanding_minor = $row->adjusted_minor - $row->reconciled_minor;

                return $row;
            })->all();
    }

    public static function record(string $association, Authenticatable $actor, array $data): string
    {
        Finance::owner($actor, $association);

        return DB::transaction(function () use ($association, $actor, $data) {
            Finance::lock($association);
            $old = DB::table('association_tds_challans')->where('association_id', $association)->where('client_token', $data['client_token'])->first();
            if ($old) {
                Finance::check($old->reference === $data['reference'] && $old->paid_on === $data['paid_on'] && (int) $old->remittance_line_id === (int) $data['remittance_line_id'] && (int) $old->amount_minor === AccountingMoney::minor($data['amount']), 'Request token already records a different challan.');

                return $old->id;
            }
            $line = self::remittances($association)->firstWhere('id', (int) $data['remittance_line_id']);
            Finance::check($line !== null, 'Choose a posted TDS payment debit from this association.');
            Finance::check(AccountingMoney::minor($data['amount']) === (int) $line->debit_minor, 'Challan amount must equal the selected remittance line. Split the payment before posting if needed.');
            Finance::check($data['paid_on'] === $line->entry_date, 'Challan date must match the posted remittance date.');
            Finance::check(! DB::table('association_tds_challans')->where('remittance_line_id', $line->id)->exists() && ! DB::table('association_tds_challans')->where('association_id', $association)->where('reference', $data['reference'])->exists(), 'This payment or challan reference is already recorded.');
            $id = (string) Str::uuid();
            DB::table('association_tds_challans')->insert(['id' => $id, 'association_id' => $association, 'remittance_line_id' => $line->id, 'reference' => $data['reference'], 'paid_on' => $data['paid_on'], 'amount_minor' => $line->debit_minor, 'client_token' => $data['client_token'], 'created_by' => $actor->getAuthIdentifier()]);
            Finance::event($association, $actor, 'tds_challan_recorded', $line->voucher_id, json_encode(['challan' => $id, 'reference' => $data['reference']], JSON_THROW_ON_ERROR));

            return $id;
        });
    }

    public static function remittances(string $association)
    {
        return DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->join('association_finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('v.association_id', $association)->where('v.status', 'posted')->where('v.kind', 'payment')->where('a.code', 'like', 'TAX-TDS-%')->where('l.debit_minor', '>', 0)
            ->get(['l.id', 'l.voucher_id', 'l.account_id', 'l.debit_minor', 'v.number', 'v.entry_date', 'v.reference']);
    }

    public static function match(string $association, Authenticatable $actor, array $data): string
    {
        Finance::owner($actor, $association);

        return DB::transaction(function () use ($association, $actor, $data) {
            Finance::lock($association);
            $minor = AccountingMoney::minor($data['amount']);
            $old = DB::table('association_tds_matches')->where('association_id', $association)->where('client_token', $data['client_token'])->first();
            if ($old) {
                Finance::check($old->challan_id === $data['challan_id'] && $old->document_id === $data['document_id'] && (int) $old->amount_minor === $minor, 'Request token already records a different match.');

                return $old->id;
            }
            $challan = DB::table('association_tds_challans')->where('association_id', $association)->where('id', $data['challan_id'])->first();
            abort_unless($challan, 404);
            $line = self::remittances($association)->firstWhere('id', $challan->remittance_line_id);
            $bill = collect(self::liabilities($association))->firstWhere('id', $data['document_id']);
            Finance::check($line !== null && $bill !== null && $bill->account_id === $line->account_id, 'Choose a posted liability in the same TDS ledger.');
            $used = (int) DB::table('association_tds_matches')->where('challan_id', $challan->id)->whereNull('released_at')->sum('amount_minor');
            Finance::check($minor > 0 && $minor <= (int) $challan->amount_minor - $used && $minor <= $bill->outstanding_minor, 'Match exceeds the available challan or adjusted liability.');
            $id = (string) Str::uuid();
            DB::table('association_tds_matches')->insert(['id' => $id, 'association_id' => $association, 'challan_id' => $challan->id, 'document_id' => $bill->id, 'amount_minor' => $minor, 'client_token' => $data['client_token'], 'created_by' => $actor->getAuthIdentifier()]);
            Finance::event($association, $actor, 'tds_matched', $bill->id, json_encode(['match' => $id, 'challan' => $challan->id, 'amount_minor' => $minor], JSON_THROW_ON_ERROR));

            return $id;
        });
    }

    public static function release(string $association, Authenticatable $actor, string $id, string $reason): void
    {
        Finance::owner($actor, $association);
        DB::transaction(function () use ($association, $actor, $id, $reason) {
            Finance::lock($association);
            Finance::check(trim($reason) !== '', 'Enter a release reason.');
            $match = DB::table('association_tds_matches')->where('association_id', $association)->where('id', $id)->first();
            abort_unless($match, 404);
            if ($match->released_at) {
                return;
            }
            DB::table('association_tds_matches')->where('id', $id)->update(['released_at' => now(), 'released_by' => $actor->getAuthIdentifier(), 'release_reason' => $reason]);
            Finance::event($association, $actor, 'tds_match_released', $match->document_id, json_encode(['match' => $id, 'reason' => $reason], JSON_THROW_ON_ERROR));
        });
    }
}
