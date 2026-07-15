<?php

namespace App\Services\Envelope;

use App\Mail\Envelopes\EnvelopeInvite;
use App\Models\Envelope;
use App\Models\EnvelopeEvent;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EnvelopeService
{
    public function __construct(private NotificationService $notification) {}

    /** Cria o envelope (draft) com signatários e posições de assinatura. */
    public function create(User $user, UploadedFile $pdf, array $data): Envelope
    {
        return DB::transaction(function () use ($user, $pdf, $data) {
            $envelope = Envelope::create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'message' => $data['message'] ?? null,
                'signing_order' => $data['signing_order'],
                'expires_at' => $data['expires_at'] ?? null,
                'original_pdf_path' => 'pending',
                'sha256_original' => hash_file('sha256', $pdf->getRealPath()),
            ]);

            $path = $pdf->storeAs("envelopes/{$envelope->id}", 'original.pdf', 'local');
            $envelope->update(['original_pdf_path' => $path]);

            foreach (array_values($data['signers']) as $i => $s) {
                $signer = $envelope->signers()->create([
                    'name' => $s['name'],
                    'email' => $s['email'],
                    'whatsapp' => $s['whatsapp'] ?? null,
                    'auth_method' => $s['auth_method'],
                    'sign_position' => $i + 1,
                ]);
                $signer->fields()->createMany($s['fields']);
            }

            $this->recordEvent($envelope, null, 'created');

            return $envelope->fresh(['signers.fields']);
        });
    }

    /** Envia os convites. Exige certificado da plataforma válido configurado. */
    public function send(Envelope $envelope): void
    {
        $cert = Setting::current()->platformCertificate;
        if ($cert === null || $cert->isExpired()) {
            throw new \RuntimeException(
                'Nenhum certificado da plataforma válido configurado — peça ao administrador para configurar em Configurações.'
            );
        }

        $envelope->update(['status' => 'sent']);
        $this->recordEvent($envelope, null, 'sent');

        $targets = $envelope->isSequential()
            ? collect([$envelope->nextPendingSigner()])->filter()
            : $envelope->signers;

        foreach ($targets as $signer) {
            $this->notifySigner($signer);
        }
    }

    /** Convite (ou lembrete) por e-mail + espelho WhatsApp. */
    public function notifySigner(EnvelopeSigner $signer, bool $reminder = false): void
    {
        Mail::to($signer->email)->send(new EnvelopeInvite($signer, $reminder));

        $this->notification->sendWhatsAppTo($signer->whatsapp,
            "📄 *{$signer->envelope->user->name}* enviou o documento *{$signer->envelope->title}* para você assinar.\n".
            'Acesse: '.route('public.sign.show', $signer->token)
        );

        if ($signer->status === 'pending') {
            $signer->update(['status' => 'notified']);
        }

        $this->recordEvent($signer->envelope, $signer, $reminder ? 'reminder_sent' : 'sent');
    }

    /** Trilha de auditoria — só INSERT, nunca update. */
    public function recordEvent(
        Envelope $envelope,
        ?EnvelopeSigner $signer,
        string $event,
        ?string $ip = null,
        ?string $userAgent = null,
        array $meta = [],
    ): EnvelopeEvent {
        return $envelope->events()->create([
            'envelope_signer_id' => $signer?->id,
            'event' => $event,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            'meta' => $meta ?: null,
        ]);
    }
}
