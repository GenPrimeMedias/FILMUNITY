<?php

namespace App\Mail;

use App\Support\AssociationBranding;
use App\Support\AssociationCorrespondence;
use Illuminate\Mail\Mailable;

final class ApplicationWorkflowNotice extends Mailable
{
    public function __construct(public array $notice) {}

    public function build(): static
    {
        AssociationCorrespondence::address($this, $this->notice['association_id'], $this->notice['reply_to']);

        return $this->subject($this->notice['heading'])->with(['branding' => AssociationBranding::data($this->notice['association_id'])])->view('mail.application-workflow-notice');
    }
}
