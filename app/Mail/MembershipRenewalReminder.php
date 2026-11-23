<?php

namespace App\Mail;

use App\Support\AssociationCorrespondence;
use Illuminate\Mail\Mailable;

final class MembershipRenewalReminder extends Mailable
{
    public function __construct(public array $branding) {}

    public function build(): static
    {
        if (isset($this->branding['association_id'])) {
            AssociationCorrespondence::address($this, $this->branding['association_id']);
        }

        return $this->subject($this->branding['association_name'].' — Membership renewal reminder')->view('mail.membership-renewal-reminder');
    }
}
