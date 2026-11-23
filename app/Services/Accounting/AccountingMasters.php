<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountingMasters
{
    public const GROUPS = [
        'assets' => ['Assets', 'ASSET', null], 'liabilities' => ['Liabilities', 'LIABILITY', null],
        'income' => ['Income', 'INCOME', null], 'expenses' => ['Expenses', 'EXPENSE', null],
        'corpus' => ['Corpus / accumulated fund', 'LIABILITY', 'liabilities'], 'sinking' => ['Sinking fund', 'LIABILITY', 'liabilities'],
        'debtors' => ['Member receivables / Sundry debtors', 'ASSET', 'assets'], 'creditors' => ['Sundry creditors', 'LIABILITY', 'liabilities'],
        'bank' => ['Bank accounts', 'ASSET', 'assets'], 'cash' => ['Cash-in-hand', 'ASSET', 'assets'],
        'subscriptions' => ['Maintenance and subscription income', 'INCOME', 'income'], 'indirect' => ['Indirect expenses', 'EXPENSE', 'expenses'],
        'taxes' => ['Duties and taxes', 'LIABILITY', 'liabilities'],
        'fixed_deposits' => ['Fixed Deposits', 'ASSET', 'assets'], 'fixed_assets' => ['Fixed Assets (Furniture, Equipment)', 'ASSET', 'assets'], 'member_dues' => ['Member Dues Receivable (Sundry Debtors)', 'ASSET', 'assets'],
        'life_membership' => ['Life Membership Fund', 'LIABILITY', 'liabilities'], 'general_reserve' => ['General Reserve', 'LIABILITY', 'liabilities'], 'vendor_payables' => ['Vendor Payables (Sundry Creditors)', 'LIABILITY', 'liabilities'],
        'annual_subscription' => ['Annual Subscription Fees', 'INCOME', 'income'], 'admission_fees' => ['Admission / Entrance Fees', 'INCOME', 'income'], 'event_sponsorship' => ['Event & Sponsorship Income', 'INCOME', 'income'], 'interest_income' => ['Interest on Bank / FD', 'INCOME', 'income'],
        'office_admin' => ['Office & Admin Expenses', 'EXPENSE', 'expenses'], 'event_meeting' => ['Event & Meeting Expenses', 'EXPENSE', 'expenses'], 'printing_stationery' => ['Printing & Stationery', 'EXPENSE', 'expenses'], 'audit_professional' => ['Audit & Professional Fees', 'EXPENSE', 'expenses'],
    ];

    // Caller owns the association lock and transaction. Safe to repeat.
    public static function seed(string $association): void
    {
        foreach (self::GROUPS as $key => [$name,$nature,$parent]) {
            DB::table('association_account_groups')->insertOrIgnore(['id' => (string) Str::uuid(), 'association_id' => $association, 'name' => $name, 'nature' => $nature, 'parent_id' => $parent ? self::group($association, $parent) : null, 'system_key' => $key, 'is_system_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function group(string $association, string $key): string
    {
        $id = DB::table('association_account_groups')->where('association_id', $association)->where('system_key', $key)->value('id');
        Finance::check($id !== null, 'Accounting groups are not initialized.');

        return $id;
    }

    public static function classification(string $association, string $type, bool $money = false, ?string $key = null): string
    {
        return self::group($association, $key ?? match ($type) {
            'asset' => $money ? 'bank' : 'assets','liability' => 'liabilities','fund' => 'corpus','income' => 'subscriptions','expense' => 'indirect'
        });
    }

    public static function syncMembers(string $association, ?string $membership = null): int
    {
        $count = 0;
        DB::table('association_memberships as m')->join('users as u', 'u.id', '=', 'm.member_id')->where('m.association_id', $association)->when($membership, fn ($q) => $q->where('m.id', $membership))->orderBy('m.id')->select('m.id', 'm.number', 'u.name')->chunk(200, function ($rows) use ($association, &$count): void {
            foreach ($rows as $row) {
                $count += DB::table('association_finance_accounts')->insertOrIgnore(['id' => (string) Str::uuid(), 'association_id' => $association, 'code' => 'M-'.str_replace('-', '', $row->id), 'name' => mb_substr($row->name.' / '.$row->number, 0, 120), 'type' => 'asset', 'is_money' => false, 'active' => true, 'group_id' => self::group($association, 'debtors'), 'membership_id' => $row->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return $count;
    }

    public static function vendor(string $association, Authenticatable $actor, array $data): void
    {
        DB::transaction(function () use ($association, $actor, $data): void {
            Finance::lock($association);
            Finance::check(Finance::settings($association) !== null, 'Initialize finance first.');
            Finance::check(! DB::table('association_finance_vendors')->where('association_id', $association)->where('reference', $data['reference'])->exists(), 'Vendor reference is already in use.');
            $id = (string) Str::uuid();
            DB::table('association_finance_vendors')->insert(['id' => $id, 'association_id' => $association, ...$data, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('association_finance_accounts')->insert(['id' => (string) Str::uuid(), 'association_id' => $association, 'code' => 'V-'.str_replace('-', '', $id), 'name' => $data['name'], 'type' => 'liability', 'is_money' => false, 'active' => true, 'group_id' => self::group($association, 'creditors'), 'vendor_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            Finance::event($association, $actor, 'vendor_created', note: $data['reference'].' '.$data['name']);
        });
    }

    // Tenant row locking serializes allocations; rollback restores the counter on failure.
    public static function number(string $association, string $date, string $kind): string
    {
        $year = (int) substr($date, 0, 4) - ((int) substr($date, 5, 2) < 4 ? 1 : 0);
        $start = "$year-04-01";
        $keys = ['association_id' => $association, 'fy_start' => $start, 'kind' => $kind];
        DB::table('association_voucher_counters')->insertOrIgnore([...$keys, 'next_number' => 1]);
        $query = DB::table('association_voucher_counters')->where($keys);
        $sequence = (int) (clone $query)->lockForUpdate()->value('next_number');
        Finance::check($sequence <= 999999999, 'Voucher sequence is exhausted.');
        $query->increment('next_number');
        $prefix = match ($kind) {
            'receipt' => 'RCPT','payment' => 'PAY','transfer' => 'CONTRA','journal' => 'JV','opening' => 'OPEN','membership' => 'MEM','reversal' => 'REV','invoice' => 'INV','bill' => 'BILL','credit_note' => 'CN','debit_note' => 'DN'
        };

        return $prefix.'-'.$year.'-'.substr((string) ($year + 1),-2).'-'.str_pad((string) $sequence,6,'0',STR_PAD_LEFT);
    }
}
