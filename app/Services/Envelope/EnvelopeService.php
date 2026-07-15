<?php

namespace App\Services\Envelope;

use App\Jobs\SealEnvelopeJob;
use App\Mail\Envelopes\EnvelopeCancelled;
use App\Mail\Envelopes\EnvelopeDeclined;
use App\Mail\Envelopes\EnvelopeInvite;
use App\Mail\Envelopes\EnvelopeOtp;
use App\Models\Envelope;
use App\Models\EnvelopeEvent;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\SignatureImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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

    public function markViewed(EnvelopeSigner $signer, ?string $ip, ?string $userAgent): void
    {
        if (in_array($signer->status, ['pending', 'notified'], true)) {
            $signer->update(['status' => 'viewed']);
        }

        $this->recordEvent($signer->envelope, $signer, 'viewed', $ip, $userAgent);
    }

    /** Gera OTP de 6 dígitos (10 min, hash no banco) e envia pelo canal do signatário. */
    public function issueOtp(EnvelopeSigner $signer): void
    {
        $code = (string) random_int(100000, 999999);

        $signer->update([
            'otp_code' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(10),
            'otp_attempts' => 0,
        ]);

        if ($signer->auth_method === 'whatsapp_otp' && $signer->whatsapp) {
            $this->notification->sendWhatsAppTo($signer->whatsapp,
                "🔐 Seu código para assinar *{$signer->envelope->title}*: *{$code}*\nVale por 10 minutos.");
        } else {
            Mail::to($signer->email)->send(new EnvelopeOtp($signer, $code));
        }

        $this->recordEvent($signer->envelope, $signer, 'otp_sent');
    }

    public function verifyOtp(EnvelopeSigner $signer, string $code): bool
    {
        $valid = $signer->otp_code !== null
            && $signer->otp_expires_at?->isFuture()
            && $signer->otp_attempts < 5
            && Hash::check($code, $signer->otp_code);

        if (! $valid) {
            $signer->increment('otp_attempts');
            $this->recordEvent($signer->envelope, $signer, 'otp_failed');

            return false;
        }

        $signer->update(['otp_code' => null, 'otp_expires_at' => null]);

        return true;
    }

    /** Registra a assinatura do convidado. NÃO valida OTP — o controller valida antes. */
    public function sign(EnvelopeSigner $signer, array $data, ?string $ip, ?string $userAgent): void
    {
        $temp = SignatureImage::storeDataUrl($data['signature']);
        $relative = "envelopes/{$signer->envelope_id}/signatures/{$signer->id}.png";
        Storage::disk('local')->put($relative, file_get_contents($temp));
        @unlink($temp);

        $signer->update([
            'name' => $data['name'],
            'cpf' => $data['cpf'],
            'signature_type' => $data['signature_type'],
            'signature_image_path' => $relative,
            'status' => 'signed',
            'signed_at' => now(),
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
        ]);

        $envelope = $signer->envelope->fresh();
        $this->recordEvent($envelope, $signer, 'signed', $ip, $userAgent, [
            'signature_type' => $data['signature_type'],
            'auth_method' => $signer->auth_method,
        ]);

        if ($envelope->allSigned()) {
            SealEnvelopeJob::dispatch($envelope);
        } elseif ($envelope->isSequential() && ($next = $envelope->nextPendingSigner())) {
            $this->notifySigner($next);
        }
    }

    /** Recusa encerra o envelope inteiro e avisa o remetente. */
    public function decline(EnvelopeSigner $signer, string $reason, ?string $ip, ?string $userAgent): void
    {
        $signer->update(['status' => 'declined', 'decline_reason' => $reason]);
        $signer->envelope->update(['status' => 'declined']);

        $this->recordEvent($signer->envelope, $signer, 'declined', $ip, $userAgent, ['reason' => $reason]);

        Mail::to($signer->envelope->user->email)->send(new EnvelopeDeclined($signer->envelope, $signer));
    }

    /** Cancela e avisa quem já tinha recebido o convite. */
    public function cancel(Envelope $envelope): void
    {
        $envelope->update(['status' => 'cancelled']);
        $this->recordEvent($envelope, null, 'cancelled');

        foreach ($envelope->signers()->where('status', '!=', 'pending')->get() as $signer) {
            Mail::to($signer->email)->send(new EnvelopeCancelled($envelope));
        }
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
