<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use ZipArchive;

final class LegacyMembershipPreview
{
    public const FIELDS = ['number', 'name', 'email', 'phone', 'address', 'category', 'starts_on', 'ends_on'];

    public const REQUIRED_FIELDS = ['number', 'name', 'email', 'category', 'starts_on'];

    public const CATEGORIES = ['yearly', 'lifetime'];

    private const MAX_ROWS = 2000;

    /** @param list<string> $headers
     * @return array<string, int>
     */
    public static function templateMapping(array $headers): array
    {
        if (count($headers) !== count(self::FIELDS) || count(array_unique($headers)) !== count(self::FIELDS) || array_diff(self::FIELDS, $headers)) {
            return [];
        }
        $mapping = [];
        foreach ($headers as $index => $field) {
            $mapping[$field] = $index;
        }

        return $mapping;
    }

    private static function check(bool $valid, string $reason): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['file' => $reason]);
        }
    }

    /** @return array<string, mixed> Parsed scalar cells with source header/sheet metadata. */
    public static function read(UploadedFile $file, int $sheet, int $header, string $delimiter): array
    {
        $path = $file->getPathname();
        $extension = strtolower($file->getClientOriginalExtension());
        self::check(in_array($extension, ['csv', 'xls', 'xlsx'], true), 'Use CSV, XLS or XLSX.');
        $rows = [];
        $sheets = ['CSV'];
        if ($extension === 'csv') {
            self::check($sheet === 0, 'CSV has only one sheet.');
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw ValidationException::withMessages(['file' => 'Unable to read the uploaded CSV.']);
            }
            try {
                while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                    self::check(count($rows) < self::MAX_ROWS + $header, 'Preview limit is 2,000 source rows. Split the file without dropping records.');
                    self::check(count($values) <= 64, 'Preview limit is 64 columns.');
                    $row = [];
                    foreach ($values as $value) {
                        $value = (string) $value;
                        if (! $rows && ! $row) {
                            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
                        }
                        self::check(mb_check_encoding($value, 'UTF-8'), 'CSV must use UTF-8 encoding.');
                        $row[] = ['value' => $value, 'formula' => str_starts_with(ltrim($value), '='), 'numeric_identifier' => false];
                    }
                    $rows[] = $row;
                }
            } finally {
                fclose($handle);
            }
        } else {
            if ($extension === 'xlsx') {
                self::inspectZip($path);
                $book = SimpleXLSX::parseFile($path);
            } else {
                self::check(file_get_contents($path, false, null, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'XLS must be an actual Excel binary workbook, not renamed HTML or CSV.');
                $book = SimpleXLS::parseFile($path);
            }
            self::check($book !== false, 'The workbook is malformed, encrypted or unsupported.');
            $sheets = array_values($book->sheetNames());
            self::check(isset($sheets[$sheet]), 'Choose an existing worksheet.');
            $book->setDateTimeFormat('Y-m-d H:i:s');
            $dimensions = $extension === 'xlsx' ? $book->dimension($sheet) : [$book->sheets[$sheet]['numCols'], $book->sheets[$sheet]['numRows']];
            self::check($dimensions[0] <= 64 && $dimensions[1] <= self::MAX_ROWS + $header, 'Preview limit is 2,000 source rows and 64 columns.');
            $xlsFormulas = $extension === 'xls' ? self::xlsFormulas($book, $sheet) : [];
            foreach ($book->rowsEx($sheet, self::MAX_ROWS + $header + 1) as $rowIndex => $cells) {
                $row = [];
                foreach ($cells as $columnIndex => $cell) {
                    $value = (string) ($cell['value'] ?? '');
                    $format = $cell['format'] ?? '';
                    if (($cell['type'] ?? '') === 'd' && preg_match('/^\d{4}-\d{2}-\d{2}T00:00:00(?:\.0+)?Z?$/D', $value)) {
                        $value = substr($value, 0, 10);
                    }
                    if (preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/D', $value)) {
                        $value = substr($value, 0, 10);
                    }
                    $numeric = $extension === 'xlsx' ? in_array($cell['type'] ?? '', ['', 'n'], true) && is_numeric($value) : ($cell['t'] ?? '') === 'n';
                    $uncertain = $numeric;
                    if ($extension === 'xlsx' && $numeric && preg_match('/^0+$/D', $format) && preg_match('/^\d{1,15}$/D', $value)) {
                        $value = str_pad($value, strlen($format), '0', STR_PAD_LEFT);
                        $uncertain = false;
                    }
                    $row[] = ['value' => $value, 'formula' => isset($xlsFormulas[$rowIndex][$columnIndex]) || ! empty($cell['f']) || ($cell['t'] ?? '') === 'f' || str_starts_with(ltrim($value), '='), 'numeric_identifier' => $uncertain];
                }
                $rows[] = $row;
            }
        }
        self::check(count($rows) >= $header, 'Header row does not exist.');
        $headers = array_map(fn ($cell) => $cell['value'], $rows[$header - 1]);
        self::check(count($headers) > 0, 'Header row is empty.');

        return ['headers' => $headers, 'source_rows' => array_slice($rows, $header), 'sheets' => $sheets, 'sheet' => $sheet, 'header_row' => $header, 'sha256' => hash_file('sha256', $path)];
    }

    private static function inspectZip(string $path): void
    {
        $zip = new ZipArchive;
        self::check($zip->open($path) === true, 'XLSX must be an actual Excel ZIP workbook.');
        try {
            self::check($zip->numFiles <= 500, 'Workbook has too many package entries.');
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    throw ValidationException::withMessages(['file' => 'Unreadable workbook package entry.']);
                }
                $total += $entry['size'];
                self::check($total <= 20 * 1024 * 1024, 'Expanded workbook exceeds the 20 MB preview limit.');
                self::check(! str_contains(strtolower($entry['name']), 'vbaproject'), 'Macro workbooks are not accepted.');
                if (str_ends_with(strtolower($entry['name']), '.xml') || str_ends_with(strtolower($entry['name']), '.rels')) {
                    $content = $zip->getFromIndex($i);
                    if ($content === false) {
                        throw ValidationException::withMessages(['file' => 'Unreadable workbook XML.']);
                    }
                    self::check(! preg_match('/<!DOCTYPE|<!ENTITY/i', $content), 'XML entities and document types are not accepted.');
                }
            }
            self::check($zip->locateName('xl/workbook.xml') !== false, 'Workbook definition is missing.');
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, mixed>  $table  Parsed upload data from read().
     * @param  array<string, int|string|null>  $mapping  Destination field to source column.
     * @return array<string, mixed> Row results and mutually exclusive summary counts.
     */
    public static function analyze(string $association, array $table, array $mapping, ?string $timezone): array
    {
        $selected = array_filter($mapping, fn ($index) => $index !== null && $index !== '');
        self::check(count(array_unique($selected)) === count($selected), 'Map each source column to only one destination field.');
        foreach ($selected as $index) {
            self::check(isset($table['headers'][(int) $index]), 'A mapped column is not present in the selected sheet.');
        }
        $members = DB::table('association_memberships as m')->join('users as u', 'u.id', '=', 'm.member_id')->where('m.association_id', $association)->get(['m.id', 'm.number', 'm.membership_type', 'u.name', 'u.email']);
        $rows = [];
        $numbers = [];
        $emails = [];
        foreach ($table['source_rows'] as $index => $cells) {
            if (! array_filter($cells, fn ($cell) => $cell['value'] !== '' || $cell['formula'])) {
                continue;
            }
            $source = $table['header_row'] + $index + 1;
            $values = array_fill_keys(self::FIELDS, '');
            $reasons = [];
            foreach (self::FIELDS as $field) {
                $column = $mapping[$field] ?? null;
                $cell = $column !== null && $column !== '' ? ($cells[(int) $column] ?? null) : null;
                $values[$field] = $cell['value'] ?? '';
                if ($cell && $cell['formula']) {
                    $reasons[] = "$field contains a formula; replace it with a verified text value.";
                }
                if ($field === 'number' && $cell && $cell['numeric_identifier']) {
                    $reasons[] = 'Numeric member number needs verification as text; original leading zeros/precision cannot be guaranteed.';
                }
                $limit = match ($field) {
                    'number', 'starts_on', 'ends_on' => 32, 'name' => 255, 'email' => 254, 'category' => 40, 'phone' => 100, default => 1000
                };
                if (mb_strlen($values[$field]) > $limit) {
                    $reasons[] = "$field exceeds the supported preview length.";
                }
            }
            foreach (self::REQUIRED_FIELDS as $required) {
                if (trim($values[$required]) === '') {
                    $reasons[] = "$required is missing; map a source column and supply a value.";
                }
            }
            if ($values['number'] !== trim($values['number'])) {
                $reasons[] = 'Member number has surrounding whitespace; source is retained exactly for correction.';
            }
            if ($values['email'] !== '' && ! filter_var(trim($values['email']), FILTER_VALIDATE_EMAIL)) {
                $reasons[] = 'Email is invalid.';
            }
            $category = match (mb_strtolower(trim($values['category']))) {
                'yearly' => 'yearly', 'lifetime', 'life-time' => 'lifetime', default => null
            };
            if (! $category) {
                $reasons[] = 'Category must map to Yearly or Life-Time; custom categories need manual review.';
            }
            foreach (['starts_on', 'ends_on'] as $field) {
                if ($values[$field] !== '' && ! self::date($values[$field])) {
                    $reasons[] = "$field is invalid; use an Excel date cell or YYYY-MM-DD.";
                }
            }
            if ($category === 'yearly' && $values['ends_on'] === '') {
                $reasons[] = 'Yearly membership requires a validity end date.';
            }
            if ($category === 'lifetime' && $values['ends_on'] !== '') {
                $reasons[] = 'Life-Time membership must not have a validity end date.';
            }
            if (self::date($values['starts_on']) && self::date($values['ends_on']) && $values['ends_on'] <= $values['starts_on']) {
                $reasons[] = 'Validity end must be after the joining date.';
            }
            if (! $timezone) {
                $reasons[] = 'Select the source timezone to interpret validity dates; no timezone is inferred.';
            }
            $numberKey = mb_strtolower(trim($values['number']));
            $emailKey = mb_strtolower(trim($values['email']));
            if ($numberKey !== '') {
                $numbers[$numberKey][] = $source;
            }
            if ($emailKey !== '') {
                $emails[$emailKey][] = $source;
            }
            $rows[] = ['source_row' => $source, 'values' => $values, 'membership_type' => $category, 'reasons' => $reasons, 'number_key' => $numberKey, 'email_key' => $emailKey];
        }
        $summary = ['ready' => 0, 'existing_matches' => 0, 'needs_correction' => 0, 'manual_review' => 0];
        foreach ($rows as &$row) {
            if (count($numbers[$row['number_key']] ?? []) > 1) {
                $row['reasons'][] = 'Duplicate member number in source rows '.implode(', ', $numbers[$row['number_key']]).'.';
            }
            $candidates = $members->filter(fn ($m) => mb_strtolower($m->number) === $row['number_key'] || ($row['email_key'] !== '' && mb_strtolower($m->email) === $row['email_key']) || mb_strtolower(trim($m->name)) === mb_strtolower(trim($row['values']['name'])))->values();
            $ambiguous = count($emails[$row['email_key']] ?? []) > 1;
            if ($ambiguous) {
                $row['reasons'][] = 'Shared email in source rows '.implode(', ', $emails[$row['email_key']]).'; manual identity review required.';
            }
            $same = $candidates->count() === 1 && mb_strtolower($candidates[0]->number) === $row['number_key'] && mb_strtolower($candidates[0]->email) === $row['email_key'] && mb_strtolower(trim($candidates[0]->name)) === mb_strtolower(trim($row['values']['name']));
            $hasErrors = count(array_filter($row['reasons'], fn ($r) => ! str_starts_with($r, 'Shared email'))) > 0;
            $row['matches'] = $candidates->map(fn ($m) => ['id' => $m->id, 'number' => $m->number, 'name' => $m->name])->all();
            $row['status'] = $hasErrors ? 'needs_correction' : (($ambiguous || ($candidates->count() > 0 && ! $same)) ? 'manual_review' : ($same ? 'existing_matches' : 'ready'));
            if ($candidates->count() > 0) {
                $row['reasons'][] = $same ? 'Existing association membership matches number, name and email; no update will be performed.' : 'Ambiguous association member match by number, email or name; resolve manually.';
            }
            $summary[$row['status']]++;
            unset($row['number_key'], $row['email_key']);
        } unset($row);

        return ['rows' => $rows, 'summary' => $summary, 'source_timezone' => $timezone, 'preview_only' => true];
    }

    private static function date(string $date): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @return array<int, array<int, true>> Formula cell coordinates, including blank cached results. */
    private static function xlsFormulas(SimpleXLS $book, int $sheet): array
    {
        // SimpleXLS omits formula metadata when the cached result is blank/error.
        // Inspect only BIFF record headers/coordinates in its already-parsed stream;
        // never evaluate formula tokens or use cached results as source identities.
        $offset = $book->boundsheets[$sheet]['offset'];
        $data = $book->data;
        $length = strlen($data);
        $formulas = [];
        while ($offset + 4 <= $length) {
            $record = unpack('vtype/vsize', substr($data, $offset, 4));
            $offset += 4;
            if ($record === false) {
                throw ValidationException::withMessages(['file' => 'Invalid XLS record header.']);
            }
            self::check($offset + $record['size'] <= $length, 'Malformed XLS record.');
            if ($record['type'] === 0x000A) {
                break;
            }
            if (in_array($record['type'], [SimpleXLS::TYPE_FORMULA, SimpleXLS::TYPE_FORMULA2], true) && $record['size'] >= 4) {
                $cell = unpack('vrow/vcolumn', substr($data, $offset, 4));
                if ($cell === false) {
                    throw ValidationException::withMessages(['file' => 'Invalid XLS formula coordinates.']);
                }
                $formulas[$cell['row']][$cell['column']] = true;
            }
            $offset += $record['size'];
        }

        return $formulas;
    }
}
