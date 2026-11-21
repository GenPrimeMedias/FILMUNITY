<?php

namespace App\Jobs;

use App\Services\Accounting\BillingReminderQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class DeliverBillingInvoiceReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public string $association, public string $reminder, public string $token) {}

    public function handle(): void
    {
        BillingReminderQueue::work($this->association, $this->reminder, $this->token);
    }

    public function failed(?Throwable $exception): void
    {
        BillingReminderQueue::interrupted($this->association, $this->reminder, $this->token);
    }
}
