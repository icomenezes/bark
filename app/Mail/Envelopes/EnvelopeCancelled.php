<?php

namespace App\Mail\Envelopes;

use App\Models\Envelope;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Envelope $envelopeModel) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: 'Envelope cancelado — '.$this->envelopeModel->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.cancelled');
    }
}
