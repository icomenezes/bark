<?php

namespace App\Services\Envelope;

use App\Models\EnvelopeEvent;

/**
 * Converte um evento da trilha na frase que sai no certificado de evidências.
 *
 * Vive separado do EvidenceReportGenerator porque é uma função pura da (evento,
 * código de verificação) — o gerador cuida só do desenho no PDF.
 *
 * Tudo que vem do usuário passa por e(); a saída é consumida por writeHTMLCell.
 */
class EventProse
{
    private const AUTH_LABELS = [
        'link' => 'link exclusivo',
        'email_otp' => 'código por e-mail',
        'whatsapp_otp' => 'código por WhatsApp',
    ];

    private const SIGNATURE_TYPE_LABELS = [
        'drawn' => 'Assinatura desenhada na tela',
        'typed' => 'Nome digitado',
    ];

    public static function for(EnvelopeEvent $event, string $verificationCode): string
    {
        $meta = $event->meta ?? [];
        $name = e($event->signer?->name ?? '');
        $upper = e(mb_strtoupper($event->signer?->name ?? ''));
        $email = e((string) ($event->signer?->email ?? ''));
        $ip = $event->ip_address ? ' - IP: '.e($event->ip_address) : '';

        return match ($event->event) {
            'created' => 'Documento <b>'.e($verificationCode).'</b> <b>criado</b>.',
            'sent' => $name === ''
                ? '<b>Assinaturas iniciadas</b>.'
                : 'Convite <b>enviado</b> para '.$name.($email !== '' ? ' - Email: '.$email : '').'.',
            'reminder_sent' => '<b>Lembrete enviado</b> para '.$name.'.',
            'viewed' => $upper.' <b>visualizou</b> o documento'.$ip.'.',
            'otp_sent' => 'Código de verificação <b>enviado</b> para '.$name.'.',
            'otp_failed' => $name.' informou um <b>código incorreto</b>'.$ip.'.',
            'consent_accepted' => $upper.' <b>aceitou o termo de assinatura eletrônica</b>'
                .(isset($meta['version']) ? ' ('.e($meta['version']).')' : '').$ip.'.',
            'cpf_mismatch' => $name.' informou um <b>CPF divergente</b> do cadastro'
                .(isset($meta['attempted']) ? ' ('.e($meta['attempted']).')' : '').$ip.'.',
            'cpf_locked' => 'Link de '.$name.' <b>bloqueado</b> após tentativas repetidas de identificação incorreta.',
            'cpf_unlocked' => 'Link de '.$name.' <b>desbloqueado</b> pelo remetente.',
            'signed' => $upper.' <b>Assinou</b>'.($email !== '' ? ' - Email: '.$email : '').$ip
                .(isset(self::AUTH_LABELS[$meta['auth_method'] ?? '']) ? ' - Autenticado com '.self::AUTH_LABELS[$meta['auth_method']] : '')
                .(isset(self::SIGNATURE_TYPE_LABELS[$meta['signature_type'] ?? '']) ? ' - '.self::SIGNATURE_TYPE_LABELS[$meta['signature_type']] : '')
                .'.',
            'declined' => $upper.' <b>recusou</b> a assinatura'
                .(isset($meta['reason']) ? ' - Motivo: '.e($meta['reason']) : '').$ip.'.',
            'cancelled' => 'Envelope <b>cancelado</b> pelo remetente.',
            'sealed' => 'Documento <b>lacrado digitalmente</b>'
                .(isset($meta['sha256_final']) ? ' - SHA-256 final: '.substr($meta['sha256_final'], 0, 24).'…' : '').'.',
            'completed' => 'Envelope <b>concluído</b> — todas as assinaturas foram coletadas.',
            'expired' => 'Envelope <b>expirado</b> sem a conclusão de todas as assinaturas.',
            default => e($event->event),
        };
    }
}
