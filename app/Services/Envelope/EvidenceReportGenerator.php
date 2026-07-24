<?php

namespace App\Services\Envelope;

use App\Models\Envelope;
use App\Models\EnvelopeEvent;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Support\ColorShade;
use Illuminate\Support\Facades\Storage;

/**
 * Gera a página de lacre anexada ao PDF final do envelope: moldura com a cor
 * da marca (settings.primary_color), QR code de verificação, dados do
 * documento, cada signatário (com preview da assinatura manuscrita) e a
 * trilha de envelope_events como timeline. Unidade: pontos PDF (pt).
 */
class EvidenceReportGenerator
{
    private const AUTH_LABELS = [
        'link' => 'Link exclusivo',
        'email_otp' => 'Código por e-mail',
        'whatsapp_otp' => 'Código por WhatsApp',
    ];

    private const SIGNATURE_TYPE_LABELS = [
        'drawn' => 'Assinatura desenhada na tela',
        'typed' => 'Nome digitado',
    ];

    private const EVENT_LABELS = [
        'created' => 'Envelope criado',
        'sent' => 'Convite enviado',
        'reminder_sent' => 'Lembrete enviado',
        'viewed' => 'Documento visualizado',
        'otp_sent' => 'Código de verificação enviado',
        'otp_failed' => 'Código de verificação incorreto',
        'signed' => 'Assinou o documento',
        'declined' => 'Recusou a assinatura',
        'cancelled' => 'Envelope cancelado',
        'sealed' => 'Documento lacrado digitalmente',
        'completed' => 'Envelope concluído',
        'expired' => 'Envelope expirado',
    ];

    /** Eventos-marco ganham ponto cheio na timeline; os demais, ponto vazado. */
    private const MILESTONE_EVENTS = ['signed', 'sealed', 'completed', 'declined', 'cancelled'];

    /** Ruído operacional interno — fica na trilha imutável do banco, não no certificado. */
    private const HIDDEN_EVENTS = ['seal_failed'];

    private const BORDER_WIDTH = 22; // pt

    private const CONTENT_MARGIN = 18; // pt, além da moldura

    private string $currentVerificationCode = '';

    /** @var array{0:int,1:int,2:int} */
    private array $primary = [12, 15, 24];

    /** @var array{0:int,1:int,2:int} */
    private array $primaryLight = [140, 143, 152];

    public function generate(Envelope $envelope): string
    {
        $settings = Setting::current();
        $this->primary = ColorShade::toRgb($settings->primary_color ?: '#0c0f18');
        $this->primaryLight = ColorShade::toRgb(ColorShade::lighten($settings->primary_color ?: '#0c0f18', 0.45));
        $this->currentVerificationCode = $envelope->verification_code;

        $margin = self::BORDER_WIDTH + self::CONTENT_MARGIN;

        $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetAutoPageBreak(true, $margin);
        $pdf->AddPage();

        $this->drawBorder($pdf);
        $this->drawHeader($pdf, $settings);
        $this->drawDocumentBlock($pdf, $envelope);

        $this->sectionTitle($pdf, 'Assinaturas', 13);

        foreach ($envelope->signers as $signer) {
            $this->ensureSpace($pdf, 56);
            $this->drawSignerRow($pdf, $signer);
        }

        $pdf->Ln(14);
        $this->ensureSpace($pdf, 60);
        $this->sectionTitle($pdf, 'Trilha de auditoria', 11);
        $pdf->Ln(4);

        $this->drawTimeline($pdf, $envelope);

        $pdf->Ln(12);
        $this->ensureSpace($pdf, 40);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(153, 153, 153);
        $pdf->MultiCell(0, 10,
            "Hash SHA-256: {$envelope->sha256_original}\n".
            'Este relatório pertence única e exclusivamente ao documento do hash acima.',
            0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $path = tempnam(sys_get_temp_dir(), 'evidence_').'.pdf';
        $pdf->Output($path, 'F');

        return $path;
    }

    /** Garante espaço vertical; se não couber, abre página nova já com a moldura. */
    private function ensureSpace(\TCPDF $pdf, float $needed): void
    {
        $limit = $pdf->getPageHeight() - self::BORDER_WIDTH - self::CONTENT_MARGIN;

        if ($pdf->GetY() + $needed > $limit) {
            $pdf->AddPage();
            $this->drawBorder($pdf);
            $pdf->SetY(self::BORDER_WIDTH + self::CONTENT_MARGIN);
        }
    }

    /** Título de seção com barra de destaque na cor da marca. */
    private function sectionTitle(\TCPDF $pdf, string $text, float $size): void
    {
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->SetFillColorArray($this->primary);
        $pdf->Rect($x, $y + 4, 3.5, $size, 'F');

        $pdf->SetXY($x + 10, $y);
        $pdf->SetFont('helvetica', 'B', $size);
        $pdf->Cell(0, $size + 8, $text, 0, 1);
        $pdf->Ln(2);
    }

    /** Moldura cross-hatch: segmentos diagonais densos em dois tons de primary_color, delimitada por um traço sólido. */
    private function drawBorder(\TCPDF $pdf): void
    {
        $w = $pdf->getPageWidth();
        $h = $pdf->getPageHeight();
        $b = self::BORDER_WIDTH;
        $step = 7;

        $pdf->SetLineWidth(1.6);

        // topo e base: hachurado diagonal cobrindo toda a espessura da moldura
        for ($x = -$b; $x < $w + $b; $x += $step) {
            $pdf->SetDrawColorArray($this->primary);
            $pdf->Line($x, 0, $x + $b, $b);
            $pdf->Line($x, $h, $x + $b, $h - $b);

            $pdf->SetDrawColorArray($this->primaryLight);
            $pdf->Line($x + $step / 2, 0, $x + $step / 2 + $b, $b);
            $pdf->Line($x + $step / 2, $h, $x + $step / 2 + $b, $h - $b);
        }

        // laterais: mesmo hachurado, só na faixa vertical de espessura $b
        for ($y = $b; $y < $h - $b; $y += $step) {
            $pdf->SetDrawColorArray($this->primary);
            $pdf->Line(0, $y, $b, $y + $b);
            $pdf->Line($w, $y, $w - $b, $y + $b);

            $pdf->SetDrawColorArray($this->primaryLight);
            $pdf->Line(0, $y + $step / 2, $b, $y + $step / 2 + $b);
            $pdf->Line($w, $y + $step / 2, $w - $b, $y + $step / 2 + $b);
        }

        // traço sólido delimitando a área tramada, para um acabamento nítido
        $pdf->SetLineWidth(2);
        $pdf->SetDrawColorArray($this->primary);
        $pdf->Rect($b, $b, $w - $b * 2, $h - $b * 2);

        $pdf->SetLineWidth(0.5);
    }

    private function drawHeader(\TCPDF $pdf, Setting $settings): void
    {
        $startY = $pdf->GetY();

        $pdf->SetFillColorArray($this->primary);
        $pdf->Circle($pdf->GetX() + 12, $startY + 12, 12, 0, 360, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $initials = $this->initials($settings->company_name ?: config('app.name'));
        $pdf->SetXY($pdf->GetX(), $startY + 5);
        $pdf->Cell(24, 14, $initials, 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY($pdf->GetX() + 30, $startY);
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->Cell(300, 16, $settings->company_name ?: config('app.name'), 0, 1);

        $pdf->SetXY($pdf->GetX() + 30, $startY + 16);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(119, 119, 119);
        $pdf->Cell(300, 10, 'Certificado gerado em '.now()->translatedFormat('d \d\e F \d\e Y, H:i:s'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetY($startY);
        $qrUrl = rtrim(config('app.url'), '/')."/verificar/{$this->currentVerificationCode}";
        $pdf->write2DBarcode($qrUrl, 'QRCODE,H', $pdf->GetPageWidth() - self::BORDER_WIDTH - self::CONTENT_MARGIN - 60, $startY, 60, 60, [], 'N');

        $pdf->SetY($startY + 48);
    }

    private function drawDocumentBlock(\TCPDF $pdf, Envelope $envelope): void
    {
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $width = $pdf->GetPageWidth() - (self::BORDER_WIDTH + self::CONTENT_MARGIN) * 2;

        $pdf->SetFillColor(247, 247, 248);
        $pdf->RoundedRect($x, $y, $width, 44, 4, '1111', 'F');
        $pdf->SetFillColorArray($this->primary);
        $pdf->Rect($x, $y + 6, 3.5, 32, 'F');

        $pdf->SetXY($x + 14, $y + 7);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell($width - 24, 16, $envelope->title, 0, 1);

        $pdf->SetX($x + 14);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->Cell($width - 24, 14, 'Código do documento '.$envelope->verification_code, 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetY($y + 56);
    }

    private function drawSignerRow(\TCPDF $pdf, EnvelopeSigner $signer): void
    {
        $y = $pdf->GetY();
        $x = $pdf->GetX();

        // check preenchido
        $pdf->SetFillColorArray($this->primary);
        $pdf->Circle($x + 8, $y + 8, 8, 0, 360, 'F');
        $pdf->SetDrawColor(255, 255, 255);
        $pdf->SetLineWidth(1.4);
        $pdf->Line($x + 4, $y + 8, $x + 7, $y + 11);
        $pdf->Line($x + 7, $y + 11, $x + 13, $y + 4);
        $pdf->SetLineWidth(0.5);

        $pdf->SetXY($x + 22, $y);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(260, 12, (string) $signer->name, 0, 2);
        $pdf->SetX($x + 22);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(119, 119, 119);
        $pdf->Cell(260, 11, (string) ($signer->email ?? $signer->whatsapp), 0, 2);
        $pdf->SetX($x + 22);
        $pdf->SetTextColor(153, 153, 153);
        $pdf->Cell(260, 11, 'Assinou em '.($signer->signed_at?->format('d/m/Y H:i:s') ?? '—'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        if ($signer->signature_image_path && Storage::disk('documents')->exists($signer->signature_image_path)) {
            $preview = tempnam(sys_get_temp_dir(), 'sig_preview_').'.png';
            file_put_contents($preview, Storage::disk('documents')->get($signer->signature_image_path));
            $pdf->Image($preview, $pdf->GetPageWidth() - self::BORDER_WIDTH - self::CONTENT_MARGIN - 100, $y, 100, 34, 'PNG');
            @unlink($preview);
        }

        $pdf->SetY($y + 46);
        $pdf->SetDrawColor(240, 240, 242);
        $pdf->Line($x, $pdf->GetY() - 6, $pdf->GetPageWidth() - self::BORDER_WIDTH - self::CONTENT_MARGIN, $pdf->GetY() - 6);
    }

    /** Timeline vertical: ponto por evento, linha conectora, rótulo amigável + data, participante e detalhes. */
    private function drawTimeline(\TCPDF $pdf, Envelope $envelope): void
    {
        $left = self::BORDER_WIDTH + self::CONTENT_MARGIN;
        $lineX = $left + 7;
        $textX = $left + 22;
        $prevDotY = null;

        foreach ($envelope->events as $event) {
            if (in_array($event->event, self::HIDDEN_EVENTS, true)) {
                continue;
            }

            $details = $this->eventDetails($event);
            $rowHeight = 26 + ($details !== null ? 10 : 0);

            $beforeY = $pdf->GetY();
            $this->ensureSpace($pdf, $rowHeight + 8);
            if ($pdf->GetY() < $beforeY) {
                $prevDotY = null; // página nova: não conectar com o ponto da página anterior
            }

            $dotY = $pdf->GetY() + 7;

            if ($prevDotY !== null) {
                $pdf->SetDrawColorArray($this->primaryLight);
                $pdf->SetLineWidth(1);
                $pdf->Line($lineX, $prevDotY + 5, $lineX, $dotY - 5);
                $pdf->SetLineWidth(0.5);
            }

            if (in_array($event->event, self::MILESTONE_EVENTS, true)) {
                $pdf->SetFillColorArray($this->primary);
                $pdf->Circle($lineX, $dotY, 4, 0, 360, 'F');
            } else {
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetDrawColorArray($this->primaryLight);
                $pdf->SetLineWidth(1.2);
                $pdf->Circle($lineX, $dotY, 3.5, 0, 360, 'DF');
                $pdf->SetLineWidth(0.5);
            }

            $label = self::EVENT_LABELS[$event->event] ?? $event->event;
            $pdf->SetXY($textX, $pdf->GetY());
            $pdf->SetFont('helvetica', 'B', 9);
            $labelWidth = $pdf->GetStringWidth($label) + 6;
            $pdf->Cell($labelWidth, 12, $label, 0, 0);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(153, 153, 153);
            $pdf->Cell(0, 12, $event->created_at->format('d/m/Y H:i:s'), 0, 1);

            $pdf->SetX($textX);
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColor(119, 119, 119);
            $participant = $event->signer?->name ?? 'Sistema';
            $pdf->Cell(0, 10, $participant.($event->ip_address ? ' · IP '.$event->ip_address : ''), 0, 1);

            if ($details !== null) {
                $pdf->SetX($textX);
                $pdf->SetTextColor(153, 153, 153);
                $pdf->SetFont('helvetica', '', 7);
                $pdf->Cell(0, 10, $details, 0, 1);
            }

            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(4);
            $prevDotY = $dotY;
        }
    }

    /** Linha de detalhe da timeline, traduzida — nunca JSON cru. */
    private function eventDetails(EnvelopeEvent $event): ?string
    {
        $meta = $event->meta ?? [];

        return match ($event->event) {
            'signed' => implode(' · ', array_filter([
                self::AUTH_LABELS[$meta['auth_method'] ?? ''] ?? null,
                self::SIGNATURE_TYPE_LABELS[$meta['signature_type'] ?? ''] ?? null,
            ])) ?: null,
            'declined' => isset($meta['reason']) ? 'Motivo: '.$meta['reason'] : null,
            'sealed' => isset($meta['sha256_final']) ? 'SHA-256 final: '.substr($meta['sha256_final'], 0, 20).'…' : null,
            default => null,
        };
    }

    private function initials(string $name): string
    {
        $words = array_filter(explode(' ', trim($name)));
        $letters = array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 0, 2));

        return implode('', $letters) ?: 'SB';
    }
}
