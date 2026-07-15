<?php

namespace App\Mail\Envelopes;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeDeclined extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Envelope $envelopeModel, public EnvelopeSigner $signer) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: 'Assinatura recusada — '.$this->envelopeModel->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.declined');
    }
}
