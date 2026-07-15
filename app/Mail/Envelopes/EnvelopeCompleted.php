<?php

namespace App\Mail\Envelopes;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeCompleted extends Mailable
{
    use Queueable, SerializesModels;

    /** $signer null = versão enviada ao remetente (download autenticado). */
    public function __construct(public Envelope $envelopeModel, public ?EnvelopeSigner $signer = null) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: 'Documento assinado por todos — '.$this->envelopeModel->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.completed');
    }
}
