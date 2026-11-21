<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class AssociationRegistrationApproved extends Mailable
{
    public function __construct(
        public string $associationName,
        public string $applicationReference,
    ) {}

    public function build(): self
    {
        $branding = \App\Support\AssociationBranding::data($this->applicationReference);
        return $this->with(['branding' => $branding])->subject('FILMUNITY association registration approved')
            ->view('mail.association-registration-approved-branded');
    }
}
