<?php

namespace App\Console\Commands;

use App\Services\Accounting\ScheduledRecurringInvoices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GenerateRecurringMemberInvoices extends Command
{
    protected $signature = 'filmunity:generate-recurring-invoices';

    protected $description = 'Generate due monthly member invoice drafts across associations; safe to repeat';

    public function handle(): int
    {
        $counts = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
        DB::table('association_invoice_templates')->select('id', 'association_id')->orderBy('id')->chunkById(100, function ($templates) use (&$counts) {
            foreach ($templates as $template) {
                try {
                    $result = ScheduledRecurringInvoices::process($template->association_id, $template->id);
                    $counts[$result['outcome']]++;
                    $this->line($template->id.': '.$result['outcome'].' ('.$result['generated_drafts'].' drafts)');
                } catch (Throwable $error) {
                    // Even an audit-storage failure must not stop other templates.
                    report($error);
                    $counts['failed']++;
                    $this->error($template->id.': failed; outcome storage unavailable.');
                }
            }
        });
        $this->info("Templates: {$counts['generated']} generated, {$counts['skipped']} skipped, {$counts['failed']} failed.");

        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
