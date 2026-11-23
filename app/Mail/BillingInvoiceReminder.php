<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class BillingInvoiceReminder extends Mailable
{
    public function __construct(public array $invoice) {}

    public function build(): self
    {
        return $this->subject('Invoice reminder: '.$this->invoice['number'])->view('mail.billing-invoice-reminder');
    }
}
