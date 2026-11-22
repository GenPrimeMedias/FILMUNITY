<?php

namespace App\Services\Accounting;

use App\Support\AssociationFinance as Finance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TaxConfigurationVersions
{
    /** @param array<string, mixed> $configuration */
    public static function record(string $association, Authenticatable $actor, array $configuration): void
    {
        foreach (['gst_registered', 'tds_enabled'] as $flag) {
            $configuration[$flag] = (bool) $configuration[$flag];
        }
        $existing = DB::table('association_tax_configuration_versions')->where('association_id', $association)->where('effective_from', $configuration['effective_from'])->first();
        if ($existing) {
            $old = json_decode($existing->reviewed_configuration, true, flags: JSON_THROW_ON_ERROR);
            foreach ($configuration as $key => $value) {
                Finance::check(($old[$key] ?? null) === $value, 'A different reviewed configuration already starts on this date. Use a new effective date; reviewed versions cannot be overwritten.');
            }

            return;
        }
        DB::table('association_tax_configuration_versions')->insert(['id' => (string) Str::uuid(), 'association_id' => $association, 'effective_from' => $configuration['effective_from'], 'reviewed_configuration' => json_encode($configuration, JSON_THROW_ON_ERROR), 'reviewed_by' => $actor->getAuthIdentifier(), 'reviewed_at' => now()->toIso8601String()]);
    }

    public static function effective(string $association, string $date): ?\stdClass
    {
        $version = DB::table('association_tax_configuration_versions')->where('association_id', $association)->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
        if (! $version) {
            return null;
        }

        return (object) [...json_decode($version->reviewed_configuration, true, flags: JSON_THROW_ON_ERROR), 'association_id' => $association, 'effective_from' => $version->effective_from, 'configuration_version_id' => $version->id, 'reviewed_by' => $version->reviewed_by, 'reviewed_at' => $version->reviewed_at];
    }

    public static function reviewRequired(string $association, string $voucher, string $date): bool
    {
        $snapshot = DB::table('association_finance_tax_documents')->where('association_id', $association)->where('voucher_id', $voucher)->value('snapshot');
        if (! $snapshot) {
            return false;
        }
        $saved = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        $savedVersion = $saved['supplier_profile']['configuration_version_id'] ?? null;

        return $savedVersion === null || $savedVersion !== self::effective($association, $date)?->configuration_version_id;
    }

    public static function validatePosting(string $association, string $voucher, string $date): void
    {
        Finance::check(! self::reviewRequired($association, $voucher, $date), 'Tax configuration review required. Reject and reissue this frozen draft under the applicable reviewed configuration; do not overwrite the saved amounts.');
    }
}
