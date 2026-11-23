<?php

namespace Database\Seeders;

use App\Services\Accounting\AccountingMasters;
use App\Support\AssociationFinance;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class AssociationChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('association_finance_settings')->orderBy('association_id')->pluck('association_id')->each(function ($id): void {
            DB::transaction(function () use ($id): void {
                AssociationFinance::lock($id);
                AccountingMasters::seed($id);
                AccountingMasters::syncMembers($id);
            });
        });
    }
}
