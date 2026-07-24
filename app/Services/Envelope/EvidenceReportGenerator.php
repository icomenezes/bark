<?php

namespace App\Services\Envelope;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Support\ColorShade;
use Illuminate\Support\Facades\Storage;

/**
 * Gera a página de lacre anexada ao PDF final do envelope: moldura com a cor
 * da marca (settings.primary_color), QR code de verificação, dados do
 * documento, cada signatário (com preview da assinatura manuscrita) e a
 * trilha completa de envelope_events. Unidade: pontos PDF (pt).
 */
class EvidenceReportGenerator
{
    private const AUTH_LABELS = [
        'link' => 'Link exclusivo por e-mail',
        'email_otp' => 'Link + código por e-mail',
        'whatsapp_otp' => 'Link + código por WhatsApp',
    ];

    private const BORDER_WIDTH = 22; // pt

    private string $currentVerificationCode = '';

    public function generate(Envelope $envelope): string
    {
        $settings = Setting::current();
        $primary = ColorShade::toRgb($settings->primary_color ?: '#0c0f18');
        $primaryLight = ColorShade::toRgb(ColorShade::lighten($settings->primary_color ?: '#0c0f18', 0.45));
        $this->currentVerificationCode = $envelope->verification_code;

        $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(self::BORDER_WIDTH + 18, self::BORDER_WIDTH + 18, self::BORDER_WIDTH + 18);
        $pdf->SetAutoPageBreak(true, self::BORDER_WIDTH + 18);
        $pdf->AddPage();

        $this->drawBorder($pdf, $primary, $primaryLight);
        $this->drawHeader($pdf, $settings, $primary);
        $this->drawDocumentBlock($pdf, $envelope, $primary);

        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->Cell(0, 20, 'Assinaturas', 0, 1);

        foreach ($envelope->signers as $signer) {
            $this->drawSignerRow($pdf, $signer, $primary);
        }

        $pdf->Ln(6);
        $pdf->SetDrawColor(238, 238, 238);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetPageWidth() - self::BORDER_WIDTH - 18, $pdf->GetY());
        $pdf->Ln(10);

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 20, 'Trilha de auditoria', 0, 1);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->writeHTML($this->eventsTable($envelope), true, false, true);

        $pdf->Ln(10);
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

    /** Moldura: traço sólido espesso em primary_color, com friso interno mais claro. */
    private function drawBorder(\TCPDF $pdf, array $primary, array $primaryLight): void
    {
        $w = $pdf->getPageWidth();
        $h = $pdf->getPageHeight();
        $b = self::BORDER_WIDTH;

        $pdf->SetLineWidth($b);
        $pdf->SetDrawColorArray($primary);
        $pdf->Rect($b / 2, $b / 2, $w - $b, $h - $b);

        $pdf->SetLineWidth(2);
        $pdf->SetDrawColorArray($primaryLight);
        $pdf->Rect($b - 3, $b - 3, $w - ($b - 3) * 2, $h - ($b - 3) * 2);

        $pdf->SetLineWidth(0.5);
    }

    private function drawHeader(\TCPDF $pdf, Setting $settings, array $primary): void
    {
        $startY = $pdf->GetY();

        $pdf->SetFillColorArray($primary);
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
        $pdf->write2DBarcode($qrUrl, 'QRCODE,H', $pdf->GetPageWidth() - self::BORDER_WIDTH - 18 - 60, $startY, 60, 60, [], 'N');

        $pdf->SetY($startY + 44);
    }

    private function drawDocumentBlock(\TCPDF $pdf, Envelope $envelope, array $primary): void
    {
        $pdf->SetFillColor(247, 247, 248);
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $width = $pdf->GetPageWidth() - self::BORDER_WIDTH * 2 - 36;

        $pdf->Rect($x, $y, $width, 40, 'F');
        $pdf->SetLineWidth(3);
        $pdf->SetDrawColorArray($primary);
        $pdf->Line($x, $y, $x, $y + 40);
        $pdf->SetLineWidth(0.5);

        $pdf->SetXY($x + 12, $y + 6);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell($width - 20, 16, $envelope->title, 0, 1);

        $pdf->SetX($x + 12);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->Cell($width - 20, 14, 'Código do documento '.$envelope->verification_code, 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetY($y + 50);
    }

    private function drawSignerRow(\TCPDF $pdf, EnvelopeSigner $signer, array $primary): void
    {
        $y = $pdf->GetY();
        $x = $pdf->GetX();

        // check preenchido
        $pdf->SetFillColorArray($primary);
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
        $pdf->Cell(260, 11, (string) $signer->email, 0, 2);
        $pdf->SetX($x + 22);
        $pdf->SetTextColor(153, 153, 153);
        $pdf->Cell(260, 11, 'Assinou em '.($signer->signed_at?->format('d/m/Y H:i:s') ?? '—'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        if ($signer->signature_image_path && Storage::disk('documents')->exists($signer->signature_image_path)) {
            $preview = tempnam(sys_get_temp_dir(), 'sig_preview_').'.png';
            file_put_contents($preview, Storage::disk('documents')->get($signer->signature_image_path));
            $pdf->Image($preview, $pdf->GetPageWidth() - self::BORDER_WIDTH - 18 - 100, $y, 100, 34, 'PNG');
            @unlink($preview);
        }

        $pdf->SetY($y + 46);
        $pdf->SetDrawColor(245, 245, 245);
        $pdf->Line($x, $pdf->GetY() - 6, $pdf->GetPageWidth() - self::BORDER_WIDTH - 18, $pdf->GetY() - 6);
    }

    private function initials(string $name): string
    {
        $words = array_filter(explode(' ', trim($name)));
        $letters = array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 0, 2));

        return implode('', $letters) ?: 'SB';
    }

    private function eventsTable(Envelope $envelope): string
    {
        $html = '<table border="0.5" cellpadding="4"><tr><th width="18%"><b>Data/hora</b></th><th width="20%"><b>Evento</b></th><th width="24%"><b>Participante</b></th><th width="18%"><b>IP</b></th><th width="20%"><b>Detalhes</b></th></tr>';

        foreach ($envelope->events as $event) {
            $html .= '<tr>'
                .'<td>'.$event->created_at->format('d/m/Y H:i:s').'</td>'
                .'<td>'.e($event->event).'</td>'
                .'<td>'.e($event->signer?->name ?? 'Sistema').'</td>'
                .'<td>'.e((string) $event->ip_address).'</td>'
                .'<td>'.e($event->meta ? json_encode($event->meta, JSON_UNESCAPED_UNICODE) : '').'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }
}
