<?php

namespace App\Console\Commands;

use App\Support\PaymentNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DeliverAssociationPaymentMail extends Command
{
    protected $signature = 'filmunity:deliver-payment-mail';
    protected $description = 'Retry up to 100 pending association payment emails';

    public function handle(): int
    {
        $ids = DB::table('association_payment_notifications')->whereNull('sent_at')
            ->where(function ($query): void {
                $query->whereNull('attempted_at')->orWhere('attempted_at', '<', now()->subMinutes(5)->toIso8601String());
            })->orderBy('created_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            PaymentNotification::deliver($id);
        }
        $this->info('Processed '.$ids->count().' pending payment notifications.');

        return self::SUCCESS;
    }
}
