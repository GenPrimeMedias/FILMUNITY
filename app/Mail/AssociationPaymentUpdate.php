<?php

namespace App\Mail;

use App\Support\AssociationBranding;
use App\Support\AssociationCorrespondence;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;

final class AssociationPaymentUpdate extends Mailable
{
    public function __construct(public string $title, public string $messageText, public string $paymentId) {}

    public function build(): static
    {
        $payment = DB::table('association_payment_requests')->where('id', $this->paymentId)->first();
        if ($payment) {
            $snapshotForReply = json_decode($payment->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $officeRecipient = in_array($snapshotForReply['official_email'] ?? '', array_column($this->to, 'address'), true);
            AssociationCorrespondence::address($this, $payment->association_id, $officeRecipient ? ($snapshotForReply['member_email'] ?? null) : null);
        }
        $branding = $payment ? AssociationBranding::data($payment->association_id) : [];
        $snapshot = json_decode($payment?->snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $branding = array_filter($snapshot, fn ($value) => $value !== null && $value !== '') + $branding;

        return $this->with(['branding' => $branding])->subject(($branding['association_name'] ?? 'Association').' — '.$this->title)->view('mail.association-payment-update');
    }
}
