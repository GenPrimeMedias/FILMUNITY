<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class BoardWorkflowNotification extends Mailable
{
    use Queueable;

    public function __construct(public string $subjectLine, public string $bodyText) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.board-workflow', with: ['bodyText' => $this->bodyText]);
    }
}
