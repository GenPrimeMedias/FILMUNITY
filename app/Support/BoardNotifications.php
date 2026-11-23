<?php

namespace App\Support;

use App\Jobs\SendBoardNotification;
use Illuminate\Support\Facades\DB;

final class BoardNotifications
{
    public static function queue(string $association, string $kind, string $dedupe, int $recipient, string $subject, string $body): void
    {
        DB::table('association_governance_notifications')->insertOrIgnore(['association_id' => $association, 'kind' => $kind, 'dedupe_key' => $dedupe, 'recipient_id' => $recipient, 'subject' => $subject, 'body' => $body, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('association_governance_notifications')->where('association_id', $association)->where('dedupe_key', $dedupe)->where('recipient_id', $recipient)->value('id');
        if ($id) {
            SendBoardNotification::dispatch((int) $id);
        }
    }
}
