<?php

namespace App\Mail\Envelopes;

use App\Models\EnvelopeSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeOtp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EnvelopeSigner $signer, public string $code) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: 'Seu código de verificação — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.otp');
    }
}
