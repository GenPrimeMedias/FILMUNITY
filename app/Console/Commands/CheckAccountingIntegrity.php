<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckAccountingIntegrity extends Command
{
    protected $signature = 'accounting:check {association : Approved association UUID}';

    protected $description = 'Read-only accounting integrity check and exact balance snapshot';

    public function handle(): int
    {
        $id = (string) $this->argument('association');
        if (! Str::isUuid($id)) {
            $this->error('Use an association UUID.');

            return self::FAILURE;
        }
        $report = DB::transaction(function () use ($id): array {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            if (! DB::table('association_finance_settings')->where('association_id', $id)->exists()) {
                return ['error' => 'Finance is not initialized for this association.'];
            }
            $rows = DB::select(<<<'SQL'
SELECT v.id,v.number,v.status,COALESCE(sum(l.debit_minor),0)::text AS debit_minor,COALESCE(sum(l.credit_minor),0)::text AS credit_minor,COUNT(DISTINCT l.account_id) AS ledgers
FROM association_finance_vouchers v LEFT JOIN association_finance_lines l ON l.voucher_id=v.id
WHERE v.association_id=? GROUP BY v.id ORDER BY v.id
SQL, [$id]);
            $unbalanced = array_values(array_map(fn ($r) => $r->number, array_filter($rows, fn ($r) => in_array($r->status, ['posted', 'reversed'], true) && ($r->debit_minor !== $r->credit_minor || bccomp($r->debit_minor, '0', 0) <= 0 || $r->ledgers < 2))));
            $foreign = DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->join('association_finance_accounts as a', 'a.id', '=', 'l.account_id')->where('v.association_id', $id)->whereColumn('a.association_id', '<>', 'v.association_id')->count();
            $missing = DB::table('association_memberships as m')->where('m.association_id', $id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('association_finance_accounts as a')->whereColumn('a.membership_id', 'm.id')->whereColumn('a.association_id', 'm.association_id'))->count();
            $ungrouped = DB::table('association_finance_accounts')->where('association_id', $id)->whereNull('group_id')->count();
            $decimal = DB::table('association_finance_lines as l')->join('association_finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->where('v.association_id', $id)->whereRaw('(l.debit*100 <> l.debit_minor OR l.credit*100 <> l.credit_minor)')->count();
            $billProblems = DB::selectOne("SELECT count(*) AS n FROM association_bill_documents d JOIN association_finance_vouchers v ON v.id=d.voucher_id WHERE d.association_id=? AND (COALESCE((SELECT sum(amount) FROM association_bill_allocations x WHERE (x.settlement_id=d.voucher_id OR x.document_id=d.voucher_id) AND x.voided_at IS NULL),0)>filmunity_bill_amount(d.voucher_id) OR (v.status<>'posted' AND EXISTS(SELECT 1 FROM association_bill_allocations x WHERE (x.settlement_id=d.voucher_id OR x.document_id=d.voucher_id) AND x.voided_at IS NULL)))", [$id])->n;
            $noteProblems = DB::selectOne("SELECT count(*) AS n FROM (SELECT n.original_document_id, SUM(filmunity_bill_amount(n.voucher_id)) AS total FROM association_finance_notes n JOIN association_finance_vouchers v ON v.id=n.voucher_id WHERE n.association_id=? AND v.status='posted' GROUP BY n.original_document_id) sums JOIN association_finance_vouchers original ON original.id=sums.original_document_id WHERE original.status<>'posted' OR sums.total>filmunity_bill_amount(original.id)", [$id])->n;

            return ['note_problems' => (int) $noteProblems, 'bill_allocation_problems' => (int) $billProblems, 'association_id' => $id, 'unbalanced_posted' => $unbalanced, 'foreign_ledger_lines' => $foreign, 'missing_member_ledgers' => $missing, 'ungrouped_ledgers' => $ungrouped, 'decimal_mismatches' => $decimal, 'vouchers' => $rows];
        });
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return isset($report['error']) || $report['unbalanced_posted'] || $report['foreign_ledger_lines'] || $report['missing_member_ledgers'] || $report['ungrouped_ledgers'] || $report['decimal_mismatches'] || $report['bill_allocation_problems'] || $report['note_problems'] ? self::FAILURE : self::SUCCESS;
    }
}
