<?php

namespace App\Services\Envelope;

use App\Models\Envelope;
use App\Models\EnvelopeEvent;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Support\ColorShade;
use Illuminate\Support\Facades\Storage;

/**
 * Gera a(s) página(s) de lacre anexadas ao PDF final do envelope, no estilo
 * "certificado de assinaturas": moldura em treliça com gradiente nas cores da
 * marca, textura de fundo de papel de segurança, cabeçalho repetido em toda
 * página, QR de verificação, lista de assinaturas com preview e eventos do
 * documento em prosa. Unidade: pontos PDF (pt).
 */
class EvidenceReportGenerator
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

    /** Ruído operacional interno — fica na trilha imutável do banco, não no certificado. */
    private const HIDDEN_EVENTS = ['seal_failed'];

    private const BORDER_WIDTH = 26; // pt

    private const CONTENT_MARGIN = 22; // pt, além da moldura

    private Setting $settings;

    private string $currentVerificationCode = '';

    /** @var array{0:int,1:int,2:int} */
    private array $primary = [12, 15, 24];

    /** @var array{0:int,1:int,2:int} */
    private array $primaryLight = [140, 143, 152];

    public function generate(Envelope $envelope): string
    {
        $this->settings = Setting::current();
        $this->primary = ColorShade::toRgb($this->settings->primary_color ?: '#0c0f18');
        $this->primaryLight = ColorShade::toRgb(ColorShade::lighten($this->settings->primary_color ?: '#0c0f18', 0.55));
        $this->currentVerificationCode = $envelope->verification_code;

        $margin = self::BORDER_WIDTH + self::CONTENT_MARGIN;

        $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetAutoPageBreak(true, $margin);

        $this->startPage($pdf);
        $this->drawTitleBlock($pdf, $envelope);

        // ── Assinaturas ──
        $pdf->SetFont('helvetica', '', 15);
        $pdf->Cell(0, 22, 'Assinaturas', 0, 1);
        $pdf->Ln(6);

        foreach ($envelope->signers as $signer) {
            $this->ensureSpace($pdf, 56);
            $this->drawSignerRow($pdf, $signer);
        }

        $pdf->Ln(8);
        $this->divider($pdf);
        $pdf->Ln(14);

        // ── Eventos do documento ──
        $this->ensureSpace($pdf, 80);
        $pdf->SetFont('helvetica', '', 15);
        $pdf->Cell(0, 22, 'Eventos do documento', 0, 1);
        $pdf->Ln(6);

        foreach ($envelope->events as $event) {
            if (in_array($event->event, self::HIDDEN_EVENTS, true)) {
                continue;
            }
            $this->drawEvent($pdf, $event);
        }

        // ── Fecho: hash + certificação ──
        $pdf->Ln(6);
        $this->ensureSpace($pdf, 110);
        $this->divider($pdf);
        $pdf->Ln(12);

        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->Cell(0, 12, 'Hash do documento original', 0, 1);
        $pdf->SetFont('courier', '', 7);
        $pdf->SetTextColor(119, 119, 119);
        $pdf->Cell(0, 10, '(SHA256): '.$envelope->sha256_original, 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(8);

        $pdf->SetFont('helvetica', '', 8);
        $pdf->writeHTMLCell(0, 0, null, null,
            'Esse certificado pertence <b>única</b> e <b>exclusivamente</b> ao documento do hash acima.',
            0, 1, false, true, 'L');
        $pdf->Ln(10);

        $companyName = $this->settings->company_name ?: config('app.name');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 12, 'Esse documento está assinado e certificado por '.$companyName, 0, 1);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->writeHTMLCell(0, 0, null, null,
            'Assinaturas eletrônicas e físicas têm igual validade legal, conforme <b>MP 2.200-2/2001</b> e <b>Lei 14.063/2020</b>.',
            0, 1, false, true, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $path = tempnam(sys_get_temp_dir(), 'evidence_').'.pdf';
        $pdf->Output($path, 'F');

        return $path;
    }

    /** Abre página nova completa: moldura, textura de fundo e cabeçalho repetido. */
    private function startPage(\TCPDF $pdf): void
    {
        $pdf->AddPage();
        $this->drawBorder($pdf);
        $this->drawWatermark($pdf);
        $this->drawHeader($pdf);
    }

    /** Garante espaço vertical; se não couber, abre página nova com moldura e cabeçalho. */
    private function ensureSpace(\TCPDF $pdf, float $needed): void
    {
        $limit = $pdf->getPageHeight() - self::BORDER_WIDTH - self::CONTENT_MARGIN;

        if ($pdf->GetY() + $needed > $limit) {
            $this->startPage($pdf);
        }
    }

    /** Divisória horizontal fina, largura total do conteúdo. */
    private function divider(\TCPDF $pdf): void
    {
        $left = self::BORDER_WIDTH + self::CONTENT_MARGIN;
        $pdf->SetDrawColor(208, 208, 212);
        $pdf->SetLineWidth(0.8);
        $pdf->Line($left, $pdf->GetY(), $pdf->getPageWidth() - $left, $pdf->GetY());
        $pdf->SetLineWidth(0.5);
    }

    /**
     * Moldura em treliça (malha de losangos finos) com gradiente diagonal
     * entre primary e primary claro, delimitada por um traço interno sólido.
     */
    private function drawBorder(\TCPDF $pdf): void
    {
        $w = $pdf->getPageWidth();
        $h = $pdf->getPageHeight();
        $b = self::BORDER_WIDTH;
        $step = 4.5;

        $pdf->SetLineWidth(0.9);

        // topo e base — cor interpolada ao longo do eixo X (gradiente)
        for ($x = -$b; $x < $w + $b; $x += $step) {
            $pdf->SetDrawColorArray($this->lerpColor(($x + $b) / ($w + 2 * $b)));
            $pdf->Line($x, 0, $x + $b, $b);
            $pdf->Line($x + $b, 0, $x, $b);
            $pdf->Line($x, $h, $x + $b, $h - $b);
            $pdf->Line($x + $b, $h, $x, $h - $b);
        }

        // laterais — cor interpolada ao longo do eixo Y
        for ($y = 0; $y < $h; $y += $step) {
            $pdf->SetDrawColorArray($this->lerpColor($y / $h));
            $pdf->Line(0, $y, $b, $y + $b);
            $pdf->Line($b, $y, 0, $y + $b);
            $pdf->Line($w, $y, $w - $b, $y + $b);
            $pdf->Line($w - $b, $y, $w, $y + $b);
        }

        // traço sólido interno delimitando a área de conteúdo
        $pdf->SetLineWidth(1.4);
        $pdf->SetDrawColorArray($this->primary);
        $pdf->Rect($b, $b, $w - $b * 2, $h - $b * 2);
        $pdf->SetLineWidth(0.5);
    }

    /** Interpola primary → primaryLight (t entre 0 e 1) para o gradiente da moldura. */
    private function lerpColor(float $t): array
    {
        $t = max(0.0, min(1.0, $t));

        return [
            (int) round($this->primary[0] + ($this->primaryLight[0] - $this->primary[0]) * $t),
            (int) round($this->primary[1] + ($this->primaryLight[1] - $this->primary[1]) * $t),
            (int) round($this->primary[2] + ($this->primaryLight[2] - $this->primary[2]) * $t),
        ];
    }

    /** Textura sutil de "papel de segurança": arcos claros sobrepostos, quase invisíveis. */
    private function drawWatermark(\TCPDF $pdf): void
    {
        $b = self::BORDER_WIDTH;
        $w = $pdf->getPageWidth();
        $h = $pdf->getPageHeight();

        $pdf->setAlpha(0.03);
        $pdf->SetLineWidth(0.8);
        $pdf->SetDrawColorArray($this->primary);

        for ($y = $b - 60; $y < $h; $y += 90) {
            for ($x = $b - 60; $x < $w; $x += 120) {
                $pdf->Circle($x, $y, 80, 0, 360, 'D');
            }
        }

        $pdf->setAlpha(1);
        $pdf->SetLineWidth(0.5);
    }

    /** Cabeçalho repetido em toda página: logo à esquerda, bloco de texto centralizado, divisória. */
    private function drawHeader(\TCPDF $pdf): void
    {
        $left = self::BORDER_WIDTH + self::CONTENT_MARGIN;
        $startY = $left - 6;
        $companyName = $this->settings->company_name ?: config('app.name');

        // logo: círculo com iniciais + nome da empresa abaixo
        $pdf->SetFillColorArray($this->primary);
        $pdf->Circle($left + 14, $startY + 14, 14, 0, 360, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetXY($left, $startY + 6);
        $pdf->Cell(28, 16, $this->initials($companyName), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($left - 6, $startY + 30);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(72, 10, $companyName, 0, 0, 'C');

        // bloco central de 3 linhas
        $pdf->SetXY($left, $startY + 2);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(85, 85, 85);
        $pdf->Cell(0, 11, 'Datas e horários baseados em Brasília, Brasil', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetX($left);
        $pdf->Cell(0, 11, 'Documento assinado eletronicamente, conforme MP 2.200-2/2001', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetX($left);
        $pdf->Cell(0, 11, 'Certificado de assinaturas gerado em '.now()->translatedFormat('d \d\e F \d\e Y, H:i:s'), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetY($startY + 48);
        $this->divider($pdf);
        $pdf->SetY($startY + 62);
    }

    /** Título do documento + código à esquerda, QR de verificação à direita, divisória abaixo. */
    private function drawTitleBlock(\TCPDF $pdf, Envelope $envelope): void
    {
        $left = self::BORDER_WIDTH + self::CONTENT_MARGIN;
        $y = $pdf->GetY() + 10;
        $qrSize = 72;
        $qrX = $pdf->getPageWidth() - $left - $qrSize;

        $qrUrl = rtrim(config('app.url'), '/')."/verificar/{$this->currentVerificationCode}";
        $pdf->write2DBarcode($qrUrl, 'QRCODE,H', $qrX, $y, $qrSize, $qrSize, [], 'N');

        $pdf->SetXY($left, $y + 14);
        $pdf->SetFont('helvetica', '', 15);
        $pdf->Cell($qrX - $left - 10, 20, $envelope->title, 0, 1);
        $pdf->SetX($left);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->Cell($qrX - $left - 10, 12, 'Código do documento '.$envelope->verification_code, 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetY($y + $qrSize + 14);
        $this->divider($pdf);
        $pdf->SetY($pdf->GetY() + 18);
    }

    /** Linha de signatário: check em anel, nome/contato/status à esquerda, preview da assinatura à direita. */
    private function drawSignerRow(\TCPDF $pdf, EnvelopeSigner $signer): void
    {
        $y = $pdf->GetY();
        $x = $pdf->GetX();

        // check em anel (círculo vazado com check dentro)
        $pdf->SetDrawColorArray($this->primary);
        $pdf->SetLineWidth(2.2);
        $pdf->Circle($x + 10, $y + 12, 10, 0, 360, 'D');
        $pdf->SetLineWidth(2);
        $pdf->Line($x + 5.5, $y + 12.5, $x + 9, $y + 16);
        $pdf->Line($x + 9, $y + 16, $x + 15, $y + 8);
        $pdf->SetLineWidth(0.5);

        $pdf->SetXY($x + 28, $y);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(280, 12, (string) $signer->name, 0, 2);
        $pdf->SetX($x + 28);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->Cell(280, 11, (string) ($signer->email ?? $signer->whatsapp), 0, 2);
        $pdf->SetX($x + 28);
        $pdf->Cell(280, 11, 'Assinou em '.($signer->signed_at?->format('d/m/Y H:i:s') ?? '—'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        if ($signer->signature_image_path && Storage::disk('documents')->exists($signer->signature_image_path)) {
            $preview = tempnam(sys_get_temp_dir(), 'sig_preview_').'.png';
            file_put_contents($preview, Storage::disk('documents')->get($signer->signature_image_path));
            $pdf->Image($preview, $pdf->getPageWidth() - self::BORDER_WIDTH - self::CONTENT_MARGIN - 100, $y, 100, 34, 'PNG');
            @unlink($preview);
        }

        $pdf->SetY($y + 50);
    }

    /** Evento no formato do certificado: data em negrito numa linha, prosa com destaques abaixo. */
    private function drawEvent(\TCPDF $pdf, EnvelopeEvent $event): void
    {
        $prose = $this->eventProse($event);
        $width = $pdf->getPageWidth() - (self::BORDER_WIDTH + self::CONTENT_MARGIN) * 2;

        $pdf->SetFont('helvetica', '', 8.5);
        $proseHeight = $pdf->getStringHeight($width, strip_tags($prose));
        $this->ensureSpace($pdf, 14 + $proseHeight + 10);

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 13, $event->created_at->translatedFormat('d M Y, H:i:s'), 0, 1);

        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(51, 51, 51);
        $pdf->writeHTMLCell($width, 0, null, null, $prose, 0, 1, false, true, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(8);
    }

    /** Prosa do evento com destaques em negrito — nunca JSON cru. */
    private function eventProse(EnvelopeEvent $event): string
    {
        $meta = $event->meta ?? [];
        $name = e($event->signer?->name ?? '');
        $upper = e(mb_strtoupper($event->signer?->name ?? ''));
        $email = e((string) ($event->signer?->email ?? ''));
        $ip = $event->ip_address ? ' - IP: '.e($event->ip_address) : '';

        return match ($event->event) {
            'created' => 'Documento <b>'.$this->currentVerificationCode.'</b> <b>criado</b>.',
            'sent' => $name === ''
                ? '<b>Assinaturas iniciadas</b>.'
                : 'Convite <b>enviado</b> para '.$name.($email !== '' ? ' - Email: '.$email : '').'.',
            'reminder_sent' => '<b>Lembrete enviado</b> para '.$name.'.',
            'viewed' => $upper.' <b>visualizou</b> o documento'.$ip.'.',
            'otp_sent' => 'Código de verificação <b>enviado</b> para '.$name.'.',
            'otp_failed' => $name.' informou um <b>código incorreto</b>'.$ip.'.',
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

    private function initials(string $name): string
    {
        $words = array_filter(explode(' ', trim($name)));
        $letters = array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 0, 2));

        return implode('', $letters) ?: 'SB';
    }
}
