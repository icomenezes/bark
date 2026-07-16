<?php

namespace App\Services\Envelope;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Support\Facades\Storage;

/**
 * Gera a(s) página(s) de evidências anexada(s) ao PDF final do envelope:
 * dados do documento, cada signatário (com a imagem da assinatura) e a
 * trilha completa de envelope_events. Unidade: pontos PDF (pt).
 */
class EvidenceReportGenerator
{
    private const AUTH_LABELS = [
        'link' => 'Link exclusivo por e-mail',
        'email_otp' => 'Link + código por e-mail',
        'whatsapp_otp' => 'Link + código por WhatsApp',
    ];

    public function generate(Envelope $envelope): string
    {
        $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(40, 40, 40);
        $pdf->SetAutoPageBreak(true, 40);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 24, 'Relatório de Assinaturas e Evidências', 0, 1);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->writeHTML($this->documentSection($envelope), true, false, true);

        $tempFiles = [];
        $disk = Storage::disk('documents');

        foreach ($envelope->signers as $signer) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 20, 'Signatário: '.$signer->name, 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->writeHTML($this->signerSection($signer), true, false, true);

            if ($signer->signature_image_path && $disk->exists($signer->signature_image_path)) {
                $temp = tempnam(sys_get_temp_dir(), 'sig_').'.png';
                file_put_contents($temp, $disk->get($signer->signature_image_path));
                $tempFiles[] = $temp;

                $pdf->Image($temp, x: 40, w: 140, h: 0, type: 'PNG');
                $pdf->Ln(10);
            }
        }

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 20, 'Trilha de auditoria', 0, 1);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->writeHTML($this->eventsTable($envelope), true, false, true);

        $path = tempnam(sys_get_temp_dir(), 'evidence_').'.pdf';
        $pdf->Output($path, 'F');

        foreach ($tempFiles as $temp) {
            @unlink($temp);
        }

        return $path;
    }

    private function documentSection(Envelope $envelope): string
    {
        $rows = [
            'Documento' => e($envelope->title),
            'Enviado por' => e($envelope->user->name).' ('.e($envelope->user->email).')',
            'Criado em' => $envelope->created_at->format('d/m/Y H:i:s'),
            'SHA-256 do documento original' => $envelope->sha256_original,
            'Ordem de assinatura' => $envelope->isSequential() ? 'Sequencial' : 'Paralela',
        ];

        return $this->table($rows);
    }

    private function signerSection(EnvelopeSigner $signer): string
    {
        $rows = [
            'Nome declarado' => e((string) $signer->name),
            'CPF declarado' => e((string) $signer->cpf),
            'E-mail' => e($signer->email),
            'Autenticação' => self::AUTH_LABELS[$signer->auth_method] ?? $signer->auth_method,
            'Assinado em' => $signer->signed_at?->format('d/m/Y H:i:s') ?? '—',
            'Endereço IP' => e((string) $signer->ip_address),
            'Navegador' => e((string) $signer->user_agent),
            'Tipo de assinatura' => $signer->signature_type === 'typed' ? 'Nome digitado' : 'Desenhada na tela',
        ];

        return $this->table($rows);
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

    private function table(array $rows): string
    {
        $html = '<table cellpadding="4">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td width="32%"><b>'.$label.'</b></td><td width="68%">'.$value.'</td></tr>';
        }

        return $html.'</table>';
    }
}
