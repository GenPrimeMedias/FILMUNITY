<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BillSettlementService
{
    public static function documents(string $association)
    {
        return DB::table('association_bill_documents as d')->join('association_finance_vouchers as v', 'v.id', '=', 'd.voucher_id')->join('association_finance_accounts as p', 'p.id', '=', 'd.party_account_id')->where('d.association_id', $association);
    }

    public static function remaining(string $voucher): string
    {
        $row = DB::selectOne('SELECT filmunity_bill_amount(?) - COALESCE((SELECT sum(amount) FROM association_bill_allocations WHERE (settlement_id=? OR document_id=?) AND voided_at IS NULL),0) AS amount', [$voucher, $voucher, $voucher]);

        return (string) $row->amount;
    }

    public static function save(string $association, Authenticatable $actor, array $data, ?string $voucher = null): string
    {
        return DB::transaction(function () use ($association, $actor, $data, $voucher): string {
            Finance::lock($association);
            $s = Finance::settings($association);
            Finance::check($s !== null, 'Initialize finance first.');
            Finance::editableDate($s, $data['entry_date']);
            if (! $voucher && ($exists = DB::table('association_bill_documents')->where('association_id', $association)->where('client_token', $data['client_token'])->value('voucher_id'))) {
                return $exists;
            }
            $party = Finance::account($association, $data['party_account_id']);
            $flow = $data['flow'];
            Finance::check(in_array($flow, ['invoice', 'receipt'], true) ? $party->membership_id !== null : $party->vendor_id !== null, 'Choose a matching member or vendor ledger.');
            $counter = Finance::account($association, $data['counter_account_id']);
            Finance::check(! $counter->membership_id && ! $counter->vendor_id, 'Counter account cannot be another party ledger.');
            Finance::check(match ($flow) {
                'invoice' => ! $counter->is_money && in_array($counter->type, ['income', 'liability', 'fund'], true),'bill' => ! $counter->is_money && in_array($counter->type, ['expense', 'asset'], true),default => (bool) $counter->is_money
            }, 'Choose the appropriate income/deferred account, expense/asset, or cash/bank account.');
            $minor = AccountingMoney::minor($data['amount']);
            Finance::check($minor > 0, 'Amount must be greater than zero.');
            $debit = in_array($flow, ['invoice', 'payment'], true) ? $party->id : $counter->id;
            $credit = $debit === $party->id ? $counter->id : $party->id;
            $lines = Finance::lines($association, [['account_id' => $debit, 'debit' => $data['amount'], 'credit' => '0'], ['account_id' => $credit, 'debit' => '0', 'credit' => $data['amount']]], $flow);
            $due = in_array($flow, ['invoice', 'bill'], true) ? ($data['due_date'] ?? null) : null;
            Finance::check(! in_array($flow, ['invoice', 'bill'], true) || ($due !== null && $due >= $data['entry_date']), 'Invoice/bill due date must be on or after its book date.');
            if ($voucher) {
                Finance::check(! DB::table('association_finance_tax_documents')->where('voucher_id', $voucher)->exists(), 'Tax snapshots are frozen. Reject/reverse and reissue through GST/TDS billing.');
                $old = self::documents($association)->where('v.id', $voucher)->select('v.*', 'd.party_account_id', 'd.bridge_source_id')->first();
                abort_if(! $old, 404);
                Finance::check($old->status === 'draft' && ! $old->bridge_source_id, 'Only ordinary billing drafts can be edited here.');
                Finance::check($old->kind === $flow && $old->party_account_id === $party->id, 'A numbered draft keeps its type and party.');
                $fy = fn ($date) => (int) substr($date, 0, 4) - ((int) substr($date, 5, 2) < 4 ? 1 : 0);
                Finance::check($fy($old->entry_date) === $fy($data['entry_date']), 'Keep the draft in its numbered financial year.');
                DB::table('association_finance_vouchers')->where('id', $voucher)->update(['entry_date' => $data['entry_date'], 'description' => $data['description'], 'reference' => $data['reference'] ?? null, 'evidence_path' => $data['evidence_path'] ?? $old->evidence_path, 'updated_at' => now()]);
                DB::table('association_bill_documents')->where('voucher_id', $voucher)->update(['due_date' => $due]);
                DB::table('association_finance_lines')->where('voucher_id', $voucher)->delete();
                foreach ($lines as $line) {
                    DB::table('association_finance_lines')->insert(['voucher_id' => $voucher, ...$line]);
                }
                Finance::event($association, $actor, 'bill_draft_updated', $voucher);
            } else {
                $voucher = Finance::insert($association, $actor, ['entry_date' => $data['entry_date'], 'kind' => $flow, 'description' => $data['description'], 'party' => $party->name, 'reference' => $data['reference'] ?? null, 'evidence_path' => $data['evidence_path'] ?? null], $lines);
                DB::table('association_bill_documents')->insert(['voucher_id' => $voucher, 'association_id' => $association, 'party_account_id' => $party->id, 'flow' => $flow, 'due_date' => $due, 'client_token' => $data['client_token'], 'created_at' => now()]);
            }

return $voucher;
        });
    }

    public static function allocate(string $association, Authenticatable $actor, array $data): void
    {
        DB::transaction(function () use ($association, $actor, $data): void {
            Finance::lock($association);
            $settings = Finance::settings($association);
            Finance::check($settings !== null, 'Initialize finance first.');
            Finance::editableDate($settings, $data['allocation_date']);
            if (DB::table('association_bill_allocations')->where('association_id', $association)->where('client_token', $data['client_token'])->exists()) {
                return;
            }
            $source = self::documents($association)->where('v.id', $data['settlement_id'])->select('d.*', 'v.entry_date', 'v.status')->first();
            $target = self::documents($association)->where('v.id', $data['document_id'])->select('d.*', 'v.entry_date', 'v.status')->first();
            Finance::check($source !== null && $target !== null, 'Choose documents belonging to this association.');
            Finance::check($source->party_account_id === $target->party_account_id && (($source->flow === 'receipt' && $target->flow === 'invoice') || ($source->flow === 'payment' && $target->flow === 'bill')), 'Settlement and bill must use the same party and direction.');
            Finance::check($source->status === 'posted' && $target->status === 'posted' && $data['allocation_date'] >= $source->entry_date && $data['allocation_date'] >= $target->entry_date, 'Allocate posted records on or after both book dates.');
            $amount = AccountingMoney::decimal($data['amount']);
            Finance::check(bccomp($amount, '0', 2) > 0 && bccomp($amount, self::remaining($source->voucher_id), 2) <= 0 && bccomp($amount, self::remaining($target->voucher_id), 2) <= 0, 'Amount exceeds the available settlement or outstanding bill.');
            DB::table('association_bill_allocations')->insert(['id' => (string) Str::uuid(), 'association_id' => $association, 'settlement_id' => $source->voucher_id, 'document_id' => $target->voucher_id, 'amount' => $amount, 'allocation_date' => $data['allocation_date'], 'note' => $data['note'], 'client_token' => $data['client_token'], 'created_by' => $actor->getAuthIdentifier(), 'created_at' => now()]);
            Finance::event($association, $actor, 'bill_allocated', $source->voucher_id, json_encode(['bill' => $target->voucher_id, 'amount' => $amount, 'note' => $data['note']], JSON_THROW_ON_ERROR));
        });
    }

    public static function release(string $association, Authenticatable $actor, string $id, string $note): void
    {
        DB::transaction(function () use ($association, $actor, $id, $note): void {
            Finance::lock($association);
            $row = DB::table('association_bill_allocations')->where('association_id', $association)->where('id', $id)->lockForUpdate()->first();
            abort_if(! $row, 404);
            Finance::check(! $row->voided_at, 'This allocation is already released.');
            Finance::editableDate(Finance::settings($association), $row->allocation_date);
            DB::table('association_bill_allocations')->where('id', $id)->update(['voided_at' => now(), 'voided_by' => $actor->getAuthIdentifier(), 'void_note' => $note]);
            Finance::event($association, $actor, 'bill_allocation_released', $row->settlement_id, json_encode(['allocation' => $id, 'note' => $note], JSON_THROW_ON_ERROR));
        });
    }

    public static function bridge(string $association, Authenticatable $actor, array $data): string
    {
        return DB::transaction(function () use ($association, $actor, $data): string {
            Finance::lock($association);
            $settings = Finance::settings($association);
            Finance::check($settings !== null, 'Initialize finance first.');
            Finance::editableDate($settings, $data['entry_date']);
            if ($old = DB::table('association_bill_documents as d')->join('association_finance_vouchers as v', 'v.id', '=', 'd.voucher_id')->where('d.association_id', $association)->where('d.bridge_source_id', $data['source_id'])->whereIn('v.status', ['draft', 'submitted', 'posted'])->value('d.voucher_id')) {
                return $old;
            }
            $v = DB::table('association_finance_vouchers as v')->join('association_payment_receipts as r', 'r.id', '=', 'v.source_id')->join('association_finance_accounts as a', 'a.membership_id', '=', 'r.membership_id')->where('v.association_id', $association)->where('a.association_id', $association)->where('v.id', $data['source_id'])->where('v.source_type', 'membership_receipt')->where('v.status', 'posted')->select('v.*', 'a.id as party_account_id', 'a.name as member_name')->first();
            Finance::check($v !== null && $data['entry_date'] >= $v->entry_date, 'Choose an unreversed posted membership receipt and an open date on or after it.');
            $lines = DB::table('association_finance_lines')->where('voucher_id', $v->id)->get();
            $credit = $lines->first(fn ($l) => (int) $l->credit_minor > 0);
            Finance::check($lines->count() === 2 && $credit !== null, 'This receipt needs accountant review; expected a two-line source entry.');
            Finance::account($association, $v->party_account_id);
            Finance::account($association, $credit->account_id);
            $id = Finance::insert($association, $actor, ['entry_date' => $data['entry_date'], 'kind' => 'journal', 'description' => 'Membership receipt reclassification: '.$v->number.'. '.$data['note'], 'party' => $v->member_name, 'source_type' => 'membership_reclass', 'source_id' => (string) Str::uuid()], [['account_id' => $credit->account_id, 'debit_minor' => (int) $credit->credit_minor, 'credit_minor' => 0], ['account_id' => $v->party_account_id, 'debit_minor' => 0, 'credit_minor' => (int) $credit->credit_minor]]);
            DB::table('association_bill_documents')->insert(['voucher_id' => $id, 'association_id' => $association, 'party_account_id' => $v->party_account_id, 'flow' => 'receipt', 'client_token' => (string) Str::uuid(), 'bridge_source_id' => $v->id, 'created_at' => now()]);
            Finance::event($association,$actor,'membership_reclass_draft',$id,$data['note']);

            return $id;
        });
    }
}
