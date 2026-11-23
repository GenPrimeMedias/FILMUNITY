<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class AssociationBranding
{
    public static function data(string $association): array
    {
        $settings = DB::table('association_workspace_settings')->where('association_id', $association)->first();
        $name = $settings?->display_name ?? DB::table('association_registration_applications')->where('id', $association)->value('name');

        return ['association_name' => $name ?? 'Association', 'logo' => $settings?->logo ? 'data:'.str_replace(';', ';base64,', $settings->logo) : null];
    }
}
