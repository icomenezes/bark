<?php

namespace App\Mail\Envelopes;

use App\Models\EnvelopeSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EnvelopeSigner $signer, public bool $reminder = false) {}

    public function envelope(): MailEnvelope
    {
        $prefix = $this->reminder ? 'Lembrete: documento aguardando sua assinatura' : 'Documento para assinar';

        return new MailEnvelope(subject: $prefix.' — '.$this->signer->envelope->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.invite');
    }
}
