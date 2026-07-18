<?php

namespace App\Services\Pdf;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;

/**
 * Fachada de assinatura digital de PDF (porte do AssinadorPdfService do ERP).
 *
 * Resolve o certificado (model Certificate) e escolhe o motor:
 *   - PyHankoSigner (preferencial): PAdES incremental, multi-assinatura, TSA embutido
 *   - SignPdfService (fallback TCPDF): assinatura única, reescreve o documento,
 *     TSA vira sidecar .tsr
 */
class PdfSignerService
{
    private const OUTPUT_DIR = 'signed';

    /** Composição assinatura+selo usada como imagem principal do carimbo (rubricas ficam sem selo). */
    private ?string $sealComposite = null;

    private function __construct(
        private string $pfxPath,
        private string $password,
        private ?string $signImage,
        private ?string $logoImage,
        private int $userId,
    ) {}

    public static function fromCertificate(Certificate $certificate): static
    {
        $disk = Storage::disk('local');

        if (empty($certificate->pfx_path) || ! $disk->exists($certificate->pfx_path)) {
            throw new \RuntimeException('Arquivo PFX do certificado não encontrado no servidor.');
        }

        if ($certificate->isExpired()) {
            throw new \RuntimeException('Certificado expirado em '.$certificate->expires_at->format('d/m/Y').'.');
        }

        $signImage = $certificate->sign_image_path ? $disk->path($certificate->sign_image_path) : null;
        $logoImage = $certificate->logo_image_path ? $disk->path($certificate->logo_image_path) : null;

        return new static(
            $disk->path($certificate->pfx_path),
            $certificate->password ?? '',
            $signImage,
            $logoImage,
            $certificate->user_id,
        );
    }

    /**
     * false quando o certificado usado não tem imagem de assinatura cadastrada
     * (ou o arquivo cadastrado não existe mais no servidor) — nesse caso o PDF
     * sai assinado digitalmente, mas sem carimbo visual algum.
     */
    public function hasSignatureImage(): bool
    {
        return ($this->signImage !== null && file_exists($this->signImage))
            || ($this->sealComposite !== null && file_exists($this->sealComposite));
    }

    /** Substitui a imagem de assinatura desta operação (ex.: assinatura desenhada na hora). */
    public function overrideSignImage(string $absolutePath): void
    {
        $this->signImage = $absolutePath;
        $this->sealComposite = null; // selo (se pedido) deve ser recomposto sobre a nova imagem
    }

    /**
     * Aplica o selo de autenticação (imagem de logo do certificado) sobre a
     * assinatura — ligeiramente acima e à direita, como um carimbo. Sem imagem
     * de assinatura, o selo sozinho vira o carimbo visual.
     */
    public function applySeal(): void
    {
        if (! $this->logoImage || ! file_exists($this->logoImage)) {
            throw new \RuntimeException(
                'Selo de autenticação solicitado, mas o certificado não tem imagem de selo/logo cadastrada.'
            );
        }

        $this->sealComposite = ($this->signImage && file_exists($this->signImage))
            ? SealComposer::compose($this->signImage, $this->logoImage)
            : $this->logoImage;
    }

    /** Motor que será usado nesta assinatura (para logs). */
    public function engine(): string
    {
        return PyHankoSigner::available() ? 'pyhanko' : 'tcpdf';
    }

    /**
     * Move o resultado (path relativo no disk local, devolvido por signExisting()/
     * createAndSign()) para o disk de destino definitivo, apagando o scratch local.
     * Retorna o path relativo no disk de destino.
     */
    public function moveToDisk(string $localRelativePath, string $targetDisk, string $targetRelativePath): string
    {
        $local = Storage::disk('local');
        $stream = $local->readStream($localRelativePath);

        Storage::disk($targetDisk)->writeStream($targetRelativePath, $stream);
        $local->delete($localRelativePath);

        return $targetRelativePath;
    }

    // ─── Operações ────────────────────────────────────────────────────────────

    /**
     * Assina um PDF existente no disco. Retorna caminho relativo no disk local.
     *
     * Com pyHanko disponível: assinatura PAdES incremental — PDFs já assinados
     * recebem uma assinatura ADICIONAL sem invalidar as anteriores.
     * Sem pyHanko: fallback TCPDF (uma única assinatura, reescreve o documento).
     *
     * @param  array  $position  ['x','y','page','w','h'] em pontos PDF
     */
    public function signExisting(string $pdfPath, bool $initialAllPages = true, array $position = [], bool $useTsa = false): string
    {
        if (! file_exists($pdfPath)) {
            throw new \RuntimeException('PDF não encontrado: '.$pdfPath);
        }

        if (PyHankoSigner::available()) {
            $input = $pdfPath;

            // Rubricas reescrevem o PDF via FPDI — só é seguro sem assinatura prévia
            if ($initialAllPages && $this->signImage && ! PyHankoSigner::isPdfSigned($pdfPath)) {
                $input = $this->newEngine($pdfPath)->stamp(true, $position, false)->save($this->tempPath());
            }

            return $this->signWithPyHanko($input, $position, $useTsa);
        }

        $relative = $this->outputRelativePath();
        $this->newEngine($pdfPath)->sign($initialAllPages, $position)->save(Storage::disk('local')->path($relative), $useTsa);

        return $relative;
    }

    /**
     * Cria PDF a partir de HTML + imagens, assina e salva. Retorna caminho relativo no disk local.
     *
     * @param  array  $position  ['x','y','page','w','h'] em pontos PDF
     */
    public function createAndSign(array $header, string $html, array $imgs = [], bool $initialAllPages = true, array $position = [], bool $useTsa = false): string
    {
        if (PyHankoSigner::available()) {
            $unsigned = $this->newEngine()
                ->createPdf($header, $html, $imgs)
                ->stamp($initialAllPages, $position, false)
                ->save($this->tempPath());

            return $this->signWithPyHanko($unsigned, $position, $useTsa);
        }

        // sign() já estampa internamente (imagem principal + rubricas)
        $relative = $this->outputRelativePath();
        $this->newEngine()
            ->createPdf($header, $html, $imgs)
            ->sign($initialAllPages, $position)
            ->save(Storage::disk('local')->path($relative), $useTsa);

        return $relative;
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    private function signWithPyHanko(string $pdfIn, array $position, bool $useTsa): string
    {
        $inputAbs = realpath($pdfIn);
        if ($inputAbs === false) {
            throw new \RuntimeException('PDF de entrada não encontrado: '.$pdfIn);
        }

        $relative = $this->outputRelativePath();
        $outputAbs = Storage::disk('local')->path($relative);

        $dir = dirname($outputAbs);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        (new PyHankoSigner)->sign(
            $inputAbs,
            $outputAbs,
            $this->pfxPath,
            $this->password,
            $position,
            $useTsa,
            $this->sealComposite ?? $this->signImage
        );

        return $relative;
    }

    private function newEngine(string $pdfDocument = ''): SignPdfService
    {
        $svc = new SignPdfService($pdfDocument);
        $svc->loadPfxCertificate($this->pfxPath, $this->password);

        if ($this->signImage) {
            $svc->setSignImage($this->signImage);
        }
        if ($this->sealComposite) {
            $svc->setMainImage($this->sealComposite);
        }
        if ($this->logoImage) {
            $svc->setLogoImage($this->logoImage);
        }

        return $svc;
    }

    private function outputRelativePath(): string
    {
        return self::OUTPUT_DIR.'/'.$this->userId.'/doc_'.bin2hex(random_bytes(12)).'.pdf';
    }

    private function tempPath(): string
    {
        return tempnam(sys_get_temp_dir(), 'stamped_').'.pdf';
    }
}
