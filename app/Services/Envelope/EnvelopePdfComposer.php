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
    /** @return array{path: string, pages: int} */
    public function compose(Envelope $envelope, string $evidencePdfPath): array
    {
        $disk = Storage::disk('local');

        $pdf = new Fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);

        // 1. Páginas do original, carimbando as assinaturas de cada uma
        $fieldsByPage = $this->fieldsByPage($envelope);
        $pageCount = $pdf->setSourceFile($disk->path($envelope->original_pdf_path));

        for ($page = 1; $page <= $pageCount; $page++) {
            $tpl = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            foreach ($fieldsByPage[$page] ?? [] as $field) {
                $pdf->Image(
                    $disk->path($field->signer->signature_image_path),
                    $field->x, $field->y, $field->w, $field->h,
                    'PNG'
                );
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
