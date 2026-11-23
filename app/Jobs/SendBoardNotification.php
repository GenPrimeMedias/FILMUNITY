<?php

namespace App\Jobs;

use App\Mail\BoardWorkflowNotification;
use App\Support\AssociationMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class SendBoardNotification implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $row = DB::table('association_governance_notifications as notification')->join('users as user', 'user.id', '=', 'notification.recipient_id')->where('notification.id', $this->notificationId)->first(['notification.*', 'user.email']);
        if (! $row || $row->state === 'sent') {
            return;
        }
        try {
            AssociationMailer::send($row->association_id, $row->email, new BoardWorkflowNotification($row->subject, $row->body));
            DB::table('association_governance_notifications')->where('id', $row->id)->update(['state' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('association_governance_notifications')->where('id', $row->id)->update(['state' => 'failed', 'failure_reason' => $e->getMessage(), 'updated_at' => now()]);
            throw $e;
        }
    }
}
