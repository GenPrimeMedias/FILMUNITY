<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class AssociationMembershipForm
{
    public const TYPES = ['text', 'textarea', 'number', 'email', 'tel', 'url', 'date', 'time', 'select', 'radio', 'multiple', 'checkbox', 'file'];

    public const MAX_FIELDS = 25;

    public static function settings(string $association): ?object
    {
        return DB::table('association_workspace_settings')->where('association_id', $association)
            ->select('display_name', 'description', 'accent', 'fields', 'version', 'yearly_fee_minor', 'lifetime_fee_minor', 'payment_instructions')
            ->selectRaw('logo IS NOT NULL AS has_logo')->first();
    }

    public static function present(object $association, ?object $settings): array
    {
        return [
            'display_name' => $settings?->display_name ?? $association->name,
            'description' => $settings?->description,
            'accent' => $settings?->accent ?? '#b9d66b',
            'logo_url' => $settings?->has_logo
                ? route('membership-applications.logo', ['association' => $association->id, 'v' => $settings->version], false)
                : null,
            'fields' => json_decode($settings?->fields ?? '[]', true, flags: JSON_THROW_ON_ERROR),
            'yearly_fee' => self::formatFee($settings?->yearly_fee_minor),
            'lifetime_fee' => self::formatFee($settings?->lifetime_fee_minor),
            'currency' => 'INR',
            'version' => (int) ($settings?->version ?? 0),
        ];
    }

    public static function feeMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function formatFee(int|string|null $minor): ?string
    {
        if ($minor === null) {
            return null;
        }

        return intdiv((int) $minor, 100).'.'.str_pad((string) ((int) $minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
