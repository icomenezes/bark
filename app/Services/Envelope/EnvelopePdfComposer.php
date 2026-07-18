<?php

namespace App\Services\Envelope;

use App\Models\Envelope;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Monta o PDF final do envelope (ANTES da assinatura digital):
 * original + carimbos das assinaturas nas posições marcadas + páginas de evidências.
 *
 * Unidade pt: envelope_fields já está em pontos PDF topo-esquerdo, aplicação direta.
 * NUNCA usar sobre PDF já assinado digitalmente (reescreve o documento).
 */
class EnvelopePdfComposer
{
    /** Arquivos temporários locais baixados do S3, para apagar ao final. */
    private array $downloadedTemps = [];

    /** @return array{path: string, pages: int} */
    public function compose(Envelope $envelope, string $evidencePdfPath): array
    {
        $disk = Storage::disk('documents');

        try {
            $pdf = new Fpdi('P', 'pt');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetAutoPageBreak(false);

            // 1. Páginas do original, carimbando as assinaturas de cada uma
            $fieldsByPage = $this->fieldsByPage($envelope);
            $originalLocal = $this->downloadToTemp($disk, $envelope->original_pdf_path);
            $pageCount = $pdf->setSourceFile($originalLocal);

            for ($page = 1; $page <= $pageCount; $page++) {
                $tpl = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);

                foreach ($fieldsByPage[$page] ?? [] as $field) {
                    $signatureLocal = $this->downloadToTemp($disk, $field->signer->signature_image_path);
                    $pdf->Image($signatureLocal, $field->x, $field->y, $field->w, $field->h, 'PNG');
                }
            }

            // 2. Páginas de evidências ao final
            $evidenceCount = $pdf->setSourceFile($evidencePdfPath);
            for ($page = 1; $page <= $evidenceCount; $page++) {
                $tpl = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }

            $path = tempnam(sys_get_temp_dir(), 'composed_').'.pdf';
            $pdf->Output($path, 'F');

            return ['path' => $path, 'pages' => $pageCount + $evidenceCount];
        } finally {
            foreach ($this->downloadedTemps as $temp) {
                @unlink($temp);
            }
            $this->downloadedTemps = [];
        }
    }

    /** Baixa um arquivo do disk documents para um temporário local; TCPDF/FPDI exigem path real. */
    private function downloadToTemp($disk, string $path): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'dl_').'_'.basename($path);
        $stream = $disk->readStream($path);
        file_put_contents($temp, $stream, FILE_BINARY);
        fclose($stream);
        $this->downloadedTemps[] = $temp;

        return $temp;
    }

    /** @return array<int, list<\App\Models\EnvelopeField>> */
    private function fieldsByPage(Envelope $envelope): array
    {
        $grouped = [];

        foreach ($envelope->signers as $signer) {
            if (! $signer->signature_image_path) {
                continue;
            }
            foreach ($signer->fields as $field) {
                $field->setRelation('signer', $signer);
                $grouped[$field->page][] = $field;
            }
        }

        return $grouped;
    }
}
