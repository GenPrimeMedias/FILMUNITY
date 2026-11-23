<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecurringMemberInvoices
{
    public static function date(string $period, int $anchor): string
    {
        $month = CarbonImmutable::parse($period)->startOfMonth();

        return $month->day(min($anchor, $month->daysInMonth))->toDateString();
    }

    private static function items(string $association, array $items): array
    {
        Finance::check(count($items) > 0 && count($items) <= 20, 'Use 1–20 invoice lines.');

        return array_map(function ($item) use ($association) {
            $account = Finance::account($association, $item['counter_account_id']);
            Finance::check(! $account->membership_id && ! $account->vendor_id && ! $account->is_money && ! str_starts_with($account->code, 'TAX-') && in_array($account->type, ['income', 'liability', 'fund'], true), 'Choose an income, fund or deferred classification ledger.');
            $amount = AccountingMoney::decimal($item['amount']);
            Finance::check(bccomp($amount, '0', 2) > 0 && trim($item['description']) !== '', 'Each line requires a description and positive amount.');

            return ['counter_account_id' => $account->id, 'counter_name' => $account->name, 'description' => $item['description'], 'amount' => $amount];
        }, $items);
    }

    public static function save(string $association, Authenticatable $actor, array $data, ?string $id = null): string
    {
        Finance::owner($actor, $association);

        return DB::transaction(function () use ($association, $actor, $data, $id) {
            Finance::lock($association);
            $old = $id ? DB::table('association_invoice_templates')->where('association_id', $association)->where('id', $id)->first() : null;
            abort_if($id && ! $old, 404);
            $partyId = $old?->party_account_id ?? $data['party_account_id'];
            $party = Finance::account($association, $partyId);
            Finance::check($party->membership_id !== null && DB::table('association_memberships')->where('association_id', $association)->where('id', $party->membership_id)->exists(), 'Choose an existing member ledger in this association.');
            $values = ['name' => $data['name'], 'items' => json_encode(self::items($association, $data['items']), JSON_THROW_ON_ERROR), 'due_days' => $data['due_days'], 'updated_at' => now()];
            if ($old) {
                DB::table('association_invoice_templates')->where('id', $id)->update($values);
            } else {
                $start = CarbonImmutable::parse($data['starts_on']);
                Finance::check(Finance::settings($association) !== null, 'Initialize finance first.');
                Finance::editableDate(Finance::settings($association), $start->toDateString());
                $id = (string) Str::uuid();
                DB::table('association_invoice_templates')->insert($values + ['id' => $id, 'association_id' => $association, 'party_account_id' => $partyId, 'starts_on' => $start->toDateString(), 'anchor_day' => $start->day, 'next_period' => $start->startOfMonth()->toDateString(), 'created_by' => $actor->getAuthIdentifier()]);
            }
            Finance::event($association, $actor, 'invoice_template_saved', note: json_encode(['template' => $id, 'values' => $values], JSON_THROW_ON_ERROR));

            return $id;
        });
    }

    public static function pause(string $association, Authenticatable $actor, string $id, bool $paused): void
    {
        Finance::owner($actor, $association);
        DB::transaction(function () use ($association, $actor, $id, $paused) {
            Finance::lock($association);
            $row = DB::table('association_invoice_templates')->where('association_id', $association)->where('id', $id)->first();
            abort_unless($row, 404);
            if ((bool) $row->paused === $paused) {
                return;
            }
            $period = $row->next_period;
            $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
            if (! $paused) {
                $month = CarbonImmutable::parse($today)->startOfMonth()->toDateString();
                if ($period < $month) {
                    $period = $month;
                }
                if (self::date($period, $row->anchor_day) < $today) {
                    $period = CarbonImmutable::parse($period)->addMonth()->toDateString();
                }
            }
            DB::table('association_invoice_templates')->where('id', $id)->update(['paused' => $paused, 'next_period' => $period, 'updated_at' => now()]);
            Finance::event($association, $actor, $paused ? 'invoice_template_paused' : 'invoice_template_resumed', note: json_encode(['template' => $id, 'old_next_period' => $row->next_period, 'next_period' => $period, 'missed_periods' => 'skip on resume'], JSON_THROW_ON_ERROR));
        });
    }

    public static function generate(string $association, Authenticatable $actor, ?string $templateId = null): int
    {
        Finance::owner($actor, $association);

        return DB::transaction(function () use ($association, $actor, $templateId) {
            Finance::lock($association);
            $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
            $count = 0;
            foreach (DB::table('association_invoice_templates')->where('association_id', $association)->when($templateId, fn ($q) => $q->where('id', $templateId))->where('paused', false)->orderBy('id')->lockForUpdate()->get() as $template) {
                $period = $template->next_period;
                for ($batch = 0; $batch < 24 && self::date($period, $template->anchor_day) <= $today; $batch++) {
                    $date = self::date($period, $template->anchor_day);
                    if (! DB::table('association_invoice_template_runs')->where('template_id', $template->id)->where('period', $period)->exists()) {
                        $party = Finance::account($association, $template->party_account_id);
                        Finance::check($party->membership_id !== null && DB::table('association_memberships')->where('association_id', $association)->where('id', $party->membership_id)->exists(), 'Template member is no longer available.');
                        $items = self::items($association, json_decode($template->items, true, flags: JSON_THROW_ON_ERROR));
                        $amount = '0.00';
                        foreach ($items as $item) {
                            $amount = bcadd($amount, $item['amount'], 2);
                        }
                        $due = CarbonImmutable::parse($date)->addDays($template->due_days)->toDateString();
                        $reference = 'Recurring '.$template->id.' '.substr($period, 0, 7);
                        $voucher = BillSettlementService::save($association, $actor, ['client_token' => (string) Str::uuid(), 'flow' => 'invoice', 'party_account_id' => $party->id, 'counter_account_id' => $items[0]['counter_account_id'], 'amount' => $amount, 'entry_date' => $date, 'due_date' => $due, 'description' => $template->name, 'reference' => $reference]);
                        $credits = [];
                        foreach ($items as $item) {
                            $credits[$item['counter_account_id']] = bcadd($credits[$item['counter_account_id']] ?? '0.00', $item['amount'], 2);
                        }
                        $input = [['account_id' => $party->id, 'debit' => $amount, 'credit' => '0']];
                        foreach ($credits as $account => $credit) {
                            $input[] = ['account_id' => $account, 'debit' => '0', 'credit' => $credit];
                        }
                        $lines = Finance::lines($association, $input, 'invoice');
                        DB::table('association_finance_lines')->where('voucher_id', $voucher)->delete();
                        foreach ($lines as $line) {
                            DB::table('association_finance_lines')->insert(['voucher_id' => $voucher] + $line);
                        }
                        $snapshot = ['template_id' => $template->id, 'name' => $template->name, 'party_account_id' => $party->id, 'party_name' => $party->name, 'items' => $items, 'period' => $period, 'entry_date' => $date, 'due_date' => $due, 'due_days' => $template->due_days, 'anchor_day' => $template->anchor_day, 'amount' => $amount];
                        DB::table('association_invoice_template_runs')->insert(['id' => (string) Str::uuid(), 'association_id' => $association, 'template_id' => $template->id, 'period' => $period, 'voucher_id' => $voucher, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_by' => $actor->getAuthIdentifier()]);
                        Finance::event($association, $actor, 'recurring_invoice_draft_generated', $voucher, json_encode($snapshot, JSON_THROW_ON_ERROR));
                        $count++;
                    }
                    $period = CarbonImmutable::parse($period)->addMonth()->toDateString();
                }
                DB::table('association_invoice_templates')->where('id', $template->id)->update(['next_period' => $period, 'updated_at' => now()]);
            }
            Finance::event($association, $actor, 'recurring_generation_run', note: json_encode(['generated_drafts' => $count, 'through' => $today], JSON_THROW_ON_ERROR));

            return $count;
        });
    }
}
