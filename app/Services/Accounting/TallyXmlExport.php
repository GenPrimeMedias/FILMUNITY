<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Illuminate\Support\Facades\DB;
use XMLWriter;

final class TallyXmlExport
{
    public const GROUPS = ['Bank Accounts', 'Cash-in-Hand', 'Current Assets', 'Current Liabilities', 'Capital Account', 'Direct Incomes', 'Indirect Incomes', 'Direct Expenses', 'Indirect Expenses'];

    private const TYPES = ['receipt' => 'Receipt', 'payment' => 'Payment', 'transfer' => 'Contra', 'journal' => 'Journal', 'reversal' => 'Journal'];

    public static function preview(string $association, string $from, string $to): array
    {
        return DB::transaction(function () use ($association, $from, $to) {
            // The same lock is used by posting and mapping changes. No ledger writes.
            Finance::lock($association);
            $accounts = DB::table('association_finance_accounts')->where('association_id', $association)->orderBy('id')->get()->keyBy('id');
            $mappings = DB::table('association_tally_ledger_mappings')->where('association_id', $association)->orderBy('account_id')->get()->keyBy('account_id');
            $vouchers = DB::table('association_finance_vouchers')->where('association_id', $association)->whereBetween('entry_date', [$from, $to])->orderBy('entry_date')->orderBy('id')->get();
            $included = [];
            $excluded = [];
            $unmapped = [];
            $movement = [];
            $masters = [];
            $debit = '0';
            $credit = '0';
            foreach ($vouchers as $voucher) {
                $reason = self::unsupported($association, $voucher);
                $lines = DB::table('association_finance_lines')->where('voucher_id', $voucher->id)->orderBy('id')->get();
                $dr = '0';
                $cr = '0';
                $exportLines = [];
                foreach ($lines as $line) {
                    $account = $accounts->get($line->account_id);
                    if (! $account || $account->membership_id || $account->vendor_id || str_starts_with($account->code, 'TAX-')) {
                        $reason ??= 'Party, tax or foreign ledger requires an unsupported mapping.';
                    }
                    $dr = bcadd($dr, (string) $line->debit_minor, 0);
                    $cr = bcadd($cr, (string) $line->credit_minor, 0);
                }
                if ($reason === null && (count($lines) < 2 || bccomp($dr, $cr, 0) !== 0 || bccomp($dr, '0', 0) <= 0)) {
                    $reason = 'Unbalanced or empty source voucher.';
                }
                if ($reason === null) {
                    foreach ($lines as $line) {
                        if (! $mappings->has($line->account_id)) {
                            $unmapped[$line->account_id] = (array) $accounts[$line->account_id];
                            $reason = 'Required ledger mapping is not verified.';
                        }
                    }
                }
                if ($reason !== null) {
                    $excluded[] = ['id' => $voucher->id, 'number' => $voucher->number, 'kind' => $voucher->kind, 'status' => $voucher->status, 'date' => $voucher->entry_date, 'reason' => $reason];

                    continue;
                }
                foreach ($lines as $line) {
                    $mapping = (array) $mappings[$line->account_id];
                    $masters[$line->account_id] = ['account_id' => $line->account_id, 'source_name' => $accounts[$line->account_id]->name, 'ledger_name' => $mapping['ledger_name'], 'parent_group' => $mapping['parent_group']];
                    $exportLines[] = ['account_id' => $line->account_id, 'ledger_name' => $mapping['ledger_name'], 'debit_minor' => (string) $line->debit_minor, 'credit_minor' => (string) $line->credit_minor];
                    $movement[$line->account_id] ??= ['account_id' => $line->account_id, 'ledger_name' => $mapping['ledger_name'], 'debit_minor' => '0', 'credit_minor' => '0'];
                    $movement[$line->account_id]['debit_minor'] = bcadd($movement[$line->account_id]['debit_minor'], (string) $line->debit_minor, 0);
                    $movement[$line->account_id]['credit_minor'] = bcadd($movement[$line->account_id]['credit_minor'], (string) $line->credit_minor, 0);
                }
                $debit = bcadd($debit, $dr, 0);
                $credit = bcadd($credit, $cr, 0);
                $included[] = ['id' => $voucher->id, 'number' => $voucher->number, 'date' => $voucher->entry_date, 'type' => self::TYPES[$voucher->kind], 'reference' => $voucher->reference, 'narration' => $voucher->description, 'reversal_of' => $voucher->reversal_of, 'lines' => $exportLines];
            }
            foreach ($movement as &$row) {
                $row['debit'] = AccountingMoney::fromMinor($row['debit_minor']);
                $row['credit'] = AccountingMoney::fromMinor($row['credit_minor']);
                $row['net_debit'] = bcdiv(bcsub($row['debit_minor'], $row['credit_minor'], 0), '100', 2);
            } unset($row);
            ksort($masters);
            ksort($movement);
            ksort($unmapped);
            $batch = ['association_id' => $association, 'from' => $from, 'to' => $to, 'included' => $included, 'excluded' => $excluded, 'unmapped' => array_values($unmapped), 'masters' => array_values($masters), 'movement' => array_values($movement), 'summary' => ['voucher_count' => count($included), 'excluded_count' => count($excluded), 'debit' => AccountingMoney::fromMinor($debit), 'credit' => AccountingMoney::fromMinor($credit)], 'opening_balances' => 'Excluded: no verified opening mapping.'];
            $batch['fingerprint'] = hash('sha256', json_encode($batch, JSON_THROW_ON_ERROR));

            return $batch;
        });
    }

    private static function unsupported(string $association, object $voucher): ?string
    {
        if (! in_array($voucher->status, ['posted', 'reversed'], true)) {
            return 'Not posted.';
        }
        if (! isset(self::TYPES[$voucher->kind])) {
            return 'Unsupported voucher type (including opening, invoice, bill, correction and membership entries).';
        }
        if ($voucher->source_type !== null || DB::table('association_bill_documents')->where('association_id', $association)->where('voucher_id', $voucher->id)->exists()) {
            return 'Source-linked or bill-allocation accounting is outside this basic export.';
        }
        if ($voucher->kind === 'reversal') {
            $original = DB::table('association_finance_vouchers')->where('association_id', $association)->where('id', $voucher->reversal_of)->first();
            if (! $original || $original->kind === 'reversal' || self::unsupported($association, $original) !== null) {
                return 'Reversal of an unsupported source.';
            }
        }

        return null;
    }

    public static function xml(array $batch, string $dataset): string
    {
        Finance::check(count($batch['unmapped']) === 0, 'Verify every required ledger mapping and refresh the preview before downloading.');
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('ENVELOPE');
        $xml->startElement('HEADER');
        $xml->writeElement('TALLYREQUEST', 'Import Data');
        $xml->endElement();
        $xml->startElement('BODY');
        $xml->startElement('IMPORTDATA');
        $xml->startElement('REQUESTDESC');
        $xml->writeElement('REPORTNAME', $dataset === 'masters' ? 'All Masters' : 'Vouchers');
        $xml->endElement();
        $xml->startElement('REQUESTDATA');
        foreach ($dataset === 'masters' ? $batch['masters'] : $batch['included'] as $row) {
            $xml->startElement('TALLYMESSAGE');
            $xml->writeAttribute('xmlns:UDF', 'TallyUDF');
            if ($dataset === 'masters') {
                $xml->startElement('LEDGER');
                $xml->writeAttribute('ACTION', 'Create');
                self::element($xml, 'NAME', $row['ledger_name']);
                self::element($xml, 'GUID', $row['account_id']);
                self::element($xml, 'PARENT', $row['parent_group']);
            } else {
                $xml->startElement('VOUCHER');
                $xml->writeAttribute('ACTION', 'Create');
                $xml->writeAttribute('VCHTYPE', $row['type']);
                $xml->writeAttribute('OBJVIEW', 'Accounting Voucher View');
                $xml->writeAttribute('REMOTEID', $row['id']);
                self::element($xml, 'GUID', $row['id']);
                self::element($xml, 'DATE', str_replace('-', '', $row['date']));
                self::element($xml, 'VOUCHERTYPENAME', $row['type']);
                self::element($xml, 'VOUCHERNUMBER', $row['number']);
                self::element($xml, 'PERSISTEDVIEW', 'Accounting Voucher View');
                self::element($xml, 'ISINVOICE', 'No');
                self::element($xml, 'REFERENCE', 'FILMUNITY:'.$batch['association_id'].':'.$row['id']);
                self::element($xml, 'NARRATION', $row['narration'].' | Source '.$row['number'].' | External '.($row['reference'] ?? '').' | Reversal of '.($row['reversal_of'] ?? 'none'));
                foreach ($row['lines'] as $line) {
                    $debit = bccomp($line['debit_minor'], '0', 0) > 0;
                    $xml->startElement('ALLLEDGERENTRIES.LIST');
                    self::element($xml, 'LEDGERNAME', $line['ledger_name']);
                    self::element($xml, 'ISDEEMEDPOSITIVE', $debit ? 'Yes' : 'No');
                    self::element($xml, 'AMOUNT', ($debit ? '-' : '').AccountingMoney::fromMinor($debit ? $line['debit_minor'] : $line['credit_minor']));
                    $xml->endElement();
                }
            }
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private static function element(XMLWriter $xml, string $key, string $value): void
    {
        Finance::check(preg_match('/^[\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/u', $value) === 1, 'Source contains characters not valid in XML 1.0.');
        $xml->writeElement($key, $value);
    }
}
