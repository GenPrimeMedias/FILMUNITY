<?php

namespace App\Services\Accounting;

use App\Models\User;
use App\Support\AssociationFinance as Finance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ScheduledRecurringInvoices
{
    public static function process(string $association, string $templateId): array
    {
        try {
            return DB::transaction(function () use ($association, $templateId) {
                $associationRow = Finance::lock($association);
                $template = DB::table('association_invoice_templates')->where('association_id', $association)->where('id', $templateId)->lockForUpdate()->first();
                abort_unless($template, 404);
                $actor = User::find($associationRow->applicant_id);
                Finance::check($actor !== null, 'Association owner is unavailable.');
                Finance::owner($actor, $association);
                $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
                if ($template->paused || RecurringMemberInvoices::date($template->next_period, $template->anchor_day) > $today) {
                    return self::outcome($association, $templateId, 'skipped', 0, $template->paused ? 'paused' : 'not due', $actor->id);
                }
                $count = RecurringMemberInvoices::generate($association, $actor, $templateId);

                return self::outcome($association, $templateId, $count ? 'generated' : 'skipped', $count, $count ? 'drafts created' : 'already generated', $actor->id);
            });
        } catch (Throwable $error) {
            // Creation, numbering and the cursor have rolled back. Keep the failed
            // attempt outside that transaction so the next command can retry safely.
            $reason = $error instanceof ValidationException ? implode(' ', array_merge(...array_values($error->errors()))) : 'Processing failed; review association status or database availability and retry.';
            report($error);

            return self::outcome($association, $templateId, 'failed', 0, $reason);
        }
    }

    private static function outcome(string $association, string $template, string $outcome, int $count, string $reason, ?int $actor = null): array
    {
        $data = ['template' => $template, 'outcome' => $outcome, 'generated_drafts' => $count, 'reason' => $reason, 'through' => CarbonImmutable::now(config('app.timezone'))->toDateString(), 'source' => 'scheduled'];
        DB::table('association_finance_events')->insert(['association_id' => $association, 'actor_id' => $actor, 'action' => 'recurring_scheduled_outcome', 'note' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);

        return $data;
    }
}
