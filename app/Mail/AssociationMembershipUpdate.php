<?php
namespace App\Mail;

use Illuminate\Mail\Mailable;

final class AssociationMembershipUpdate extends Mailable
{
    public function __construct(public string $associationId, public string $heading, public string $bodyText) {}
    public function build(): static
    {
        $branding = \App\Support\AssociationBranding::data($this->associationId);
        return $this->subject($branding['association_name'].' — '.$this->heading)
            ->with(['branding' => $branding])->view('mail.association-membership-update');
    }
}
