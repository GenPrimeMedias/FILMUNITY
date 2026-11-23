<?php

namespace App\Services;

final class LegacyMembershipTemplate
{
    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return ['headers' => LegacyMembershipPreview::FIELDS, 'categories' => LegacyMembershipPreview::CATEGORIES,
            'example' => [
                ['000123', 'Synthetic Member செயற்கை உறுப்பினர்', 'synthetic.yearly@example.test', '+910001234567', 'Synthetic address, Chennai', 'yearly', '2020-01-01', '2027-01-01'],
                ['000124', 'Synthetic Life Member', 'synthetic.life@example.test', '0009876543', '', 'lifetime', '2019-01-01', ''],
            ],
            'guidance' => [
                ['FILMUNITY membership preview template', 'Phase 1: preview only. No members, payments or numbering changes.'],
                ['Using this workbook', 'Upload the Members sheet (index 0), header row 1. Exact headers map automatically.'],
                ['Source timezone', 'Select the original timezone in the preview, e.g. Asia/Kolkata or UTC. It is not inferred.'],
                ['Required fields', implode(', ', LegacyMembershipPreview::REQUIRED_FIELDS).'; ends_on is required for yearly membership.'],
                ['Optional fields', 'phone, address; ends_on must be empty for lifetime membership.'],
                ['number', 'Text, maximum 32 characters. Preserve leading zeros. Never use formulas or numeric identifiers.'],
                ['name', 'Full name, maximum 255 characters. Unicode and Tamil are supported.'],
                ['email', 'Valid email, maximum 254 characters. Shared addresses require manual identity review.'],
                ['phone / address', 'Source contact context only. Phone is text; no native phone/address fields are written.'],
                ['category', 'Use yearly or lifetime. The preview also accepts Life-Time, case-insensitively.'],
                ['starts_on / ends_on', 'Date-only Excel cells displayed yyyy-mm-dd, or exact YYYY-MM-DD text. No formulas.'],
                ['Yearly validity', 'ends_on must be strictly after starts_on. Expired dates are retained, never renewed.'],
                ['Statuses', 'No status field is accepted by this preview. Do not add a status column. No status is assigned.'],
                ['Source IDs', 'Not accepted as import fields. Keep source IDs as text in separate source records or guidance only.'],
                ['Old CSV differences', 'membership_number → number; full_name → name; membership_type → category; joined_at → starts_on; valid_until → ends_on.'],
                ['Unsupported old fields', 'source_system, source_member_id, status, source_timezone, legacy_payment_reference, legacy_document_filename, decision_note are not data-sheet fields.'],
                ['Synthetic source reference (not imported)', 'LEGACY-000001'],
                ['Limits', '2 MB per upload; 2,000 data rows; 64 columns. CSV must be UTF-8; comma is the template delimiter.'],
                ['CSV identifiers', 'CSV cannot store Excel column types. Import number and phone columns as Text when opening/editing in Excel.'],
                ['Review results', 'Ready means validation-only. Existing and ambiguous matches never merge or update accounts.'],
                ['Example workbook', 'All example names, contacts and source references are synthetic. Remove example rows before adding real data.'],
            ],
        ];
    }

    public static function csv(): string
    {
        return "\xEF\xBB\xBF".implode(',', LegacyMembershipPreview::FIELDS)."\r\n";
    }
}
