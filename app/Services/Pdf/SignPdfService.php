<?php

namespace App\Services\Pdf;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Motor TCPDF+FPDI: geração de PDF, estampagem de rubricas e assinatura fallback.
 *
 * Limites conhecidos (portados do ERP, auditoria 2026-07-13):
 *  - setSignature() recebe PEM cru — prefixo data:// quebra openssl_pkcs7_sign
 *    silenciosamente (PDF sai com /Contents zerado)
 *  - PDF_UNIT = mm; posições chegam em pontos PDF e são convertidas por getScaleFactor()
 *  - applyTSA do TCPDF é stub: o carimbo TSA vira sidecar .tsr (nunca anexar bytes
 *    ao PDF assinado — viola o ByteRange e invalida a assinatura)
 *  - stamp() reescreve o documento via FPDI — NUNCA usar sobre PDF já assinado
 */
class SignPdfService
{
    private Fpdi $pdf;

    private int $pages = 0;

    private string $pdfDocument;

    private string $certPem = '';

    private string $keyPem = '';

    private string $extraCertsFile = '';

    private array $certInfo = [];

    private ?string $signImagePath = null;

    private ?string $logoImagePath = null;

    private const TSA_URL = 'http://timestamp.digicert.com';

    public function __construct(string $pdfDocument = '')
    {
        $this->pdfDocument = $pdfDocument !== '' ? $this->normalizePdf($pdfDocument) : '';
        $this->pdf = $this->newPdf();

        if ($this->pdfDocument !== '') {
            $this->pages = $this->pdf->setSourceFile($this->pdfDocument);
        }
    }

    // ─── Certificado ─────────────────────────────────────────────────────────

    public function loadPfxCertificate(string $pfxPath, string $password): void
    {
        if (! file_exists($pfxPath)) {
            throw new \RuntimeException("Arquivo PFX não encontrado: {$pfxPath}");
        }

        $pfxContent = file_get_contents($pfxPath);
        $p12 = [];

        if (! openssl_pkcs12_read($pfxContent, $p12, $password)) {
            throw new \RuntimeException('Falha ao ler PFX: senha incorreta ou arquivo corrompido.');
        }

        $this->certPem = $p12['cert'];
        $this->keyPem = $p12['pkey'];
        $this->certInfo = openssl_x509_parse($this->certPem) ?: [];

        // Cadeia intermediária do PFX: openssl_pkcs7_sign exige caminho de arquivo
        $this->extraCertsFile = '';
        if (! empty($p12['extracerts'])) {
            $file = tempnam(sys_get_temp_dir(), 'chain_');
            if ($file !== false && file_put_contents($file, implode("\n", $p12['extracerts'])) !== false) {
                $this->extraCertsFile = $file;
            }
        }
    }

    public function setSignImage(string $path): void
    {
        $this->signImagePath = $path;
    }

    public function setLogoImage(string $path): void
    {
        $this->logoImagePath = $path;
    }

    // ─── Criação de PDF ──────────────────────────────────────────────────────

    public function createPdf(array $header, string $html, array $imgs = []): static
    {
        $this->pdf = $this->newPdf();
        $this->pdf->SetCreator(PDF_CREATOR);
        $this->pdf->SetAuthor(config('app.name'));

        if ($header) {
            $logo = $this->logoImagePath && file_exists($this->logoImagePath) ? $this->logoImagePath : '';
            $this->pdf->SetHeaderData($logo, $logo !== '' ? 25 : 0, $header['title'] ?? '', $header['subtitle'] ?? '');
            $this->pdf->setFooterData([0, 64, 0], [0, 64, 128]);
            $this->pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', 15]);
            $this->pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
            $this->pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        }

        $this->pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $this->pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $this->pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $this->pdf->SetAutoPageBreak(true, 5);
        $this->pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $this->pdf->AddPage();
        $this->pdf->writeHTMLCell(0, 10, '', 10, $html, 0, 1, 0, true, '', true);

        foreach ($imgs as $i => $img) {
            $this->pdf->AddPage();
            $this->pdf->SetFont('helvetica', '', 12);
            $this->pdf->Cell(0, 0, 'Imagem '.($i + 1));
            $this->pdf->image($img, 15, 45, 180, 150, 'JPG');
        }

        $this->pdfDocument = '';
        $this->pages = 0;

        return $this;
    }

    // ─── Assinatura ──────────────────────────────────────────────────────────

    /**
     * Estampa imagens (assinatura visual e rubricas) e assina digitalmente via TCPDF.
     *
     * @param  array  $position  ['x','y','page','w','h'] em pontos PDF (0,0 = topo esquerdo)
     */
    public function sign(bool $initialAllPages = true, array $position = []): static
    {
        if (empty($this->certPem) || empty($this->keyPem)) {
            throw new \RuntimeException('Certificado não carregado. Chame loadPfxCertificate() antes de sign().');
        }

        $this->stamp($initialAllPages, $position, true);

        $cn = $this->certInfo['subject']['CN'] ?? config('app.name');
        if (is_array($cn)) {
            $cn = implode(', ', $cn);
        }

        // PEM cru: openssl_pkcs7_sign aceita conteúdo PEM direto; prefixo data:// quebra o parse
        $this->pdf->setSignature($this->certPem, $this->keyPem, '', $this->extraCertsFile, 2, [
            'Name' => $cn,
            'Location' => 'Brasil',
            'Reason' => 'Assinatura digital',
            'ContactInfo' => config('app.url'),
        ]);

        $k = $this->pdf->getScaleFactor();
        $this->pdf->setSignatureAppearance(
            ((float) ($position['x'] ?? 150)) / $k,
            ((float) ($position['y'] ?? 240)) / $k,
            ((float) ($position['w'] ?? 50)) / $k,
            ((float) ($position['h'] ?? 25)) / $k,
            max(1, (int) ($position['page'] ?? 1))
        );

        return $this;
    }

    /**
     * Estampa imagens sem assinar digitalmente. Reescreve o PDF via FPDI —
     * NUNCA usar sobre PDF já assinado digitalmente (invalida a assinatura).
     *
     * @param  array  $position  ['x','y','page','w','h'] em pontos PDF (0,0 = topo esquerdo)
     * @param  bool  $withMainImage  false = só rubricas (o assinador externo desenha a principal)
     */
    public function stamp(bool $initialAllPages = true, array $position = [], bool $withMainImage = true): static
    {
        // Posição chega em pontos PDF; TCPDF opera em PDF_UNIT (mm) — converte pelo fator k
        $k = $this->pdf->getScaleFactor();
        $signPage = max(1, (int) ($position['page'] ?? 1));
        $signX = ((float) ($position['x'] ?? 150)) / $k;
        $signY = ((float) ($position['y'] ?? 240)) / $k;
        $signW = ((float) ($position['w'] ?? 50)) / $k;
        $signH = ((float) ($position['h'] ?? 25)) / $k;

        $signImg = $this->signImagePath;
        if ($signImg && ! file_exists($signImg)) {
            $signImg = null;
        }

        if ($this->pdfDocument !== '') {
            $this->pdf = $this->newPdf();
            $this->pdf->setPrintHeader(false);
            $this->pdf->setPrintFooter(false);
            $this->pdf->SetAutoPageBreak(false);
            $this->pdf->SetMargins(0, 0, 0);
            $this->pages = $this->pdf->setSourceFile($this->pdfDocument);

            for ($i = 1; $i <= $this->pages; $i++) {
                $template = $this->pdf->importPage($i);
                $size = $this->pdf->getTemplateSize($template);
                $this->pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $this->pdf->useTemplate($template);
                $this->stampPage($i, $signPage, $initialAllPages, $signImg, $signX, $signY, $signW, $signH, $withMainImage);
            }
        } else {
            $this->pdf->SetAutoPageBreak(false);
            $total = $this->pdf->getNumPages();
            for ($i = 1; $i <= $total; $i++) {
                $this->pdf->setPage($i);
                $this->stampPage($i, $signPage, $initialAllPages, $signImg, $signX, $signY, $signW, $signH, $withMainImage);
            }
        }

        return $this;
    }

    private function stampPage(int $page, int $signPage, bool $initial, ?string $img, float $x, float $y, float $w, float $h, bool $withMainImage = true): void
    {
        if (! $img) {
            return;
        }

        if ($page === $signPage) {
            if ($withMainImage) {
                $this->pdf->Image($img, $x, $y, $w, $h);
            }
        } elseif ($initial) {
            // Rubrica: versão reduzida da imagem no canto inferior direito
            $rw = $w * 0.5;
            $rh = $h * 0.5;
            $margin = 10 / $this->pdf->getScaleFactor();
            $this->pdf->Image(
                $img,
                $this->pdf->getPageWidth() - $rw - $margin,
                $this->pdf->getPageHeight() - $rh - $margin,
                $rw,
                $rh
            );
        }
    }

    // ─── Saída ───────────────────────────────────────────────────────────────

    /** Grava o PDF no caminho absoluto informado e retorna esse caminho. */
    public function save(string $absolutePath, bool $useTsa = false): string
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdf->Output($absolutePath, 'F');

        if ($this->extraCertsFile !== '' && file_exists($this->extraCertsFile)) {
            @unlink($this->extraCertsFile);
            $this->extraCertsFile = '';
        }

        if ($useTsa) {
            $this->applyTimestamp($absolutePath);
        }

        return $absolutePath;
    }

    // ─── TSA (sidecar .tsr) ──────────────────────────────────────────────────

    private function applyTimestamp(string $pdfPath): void
    {
        $content = file_get_contents($pdfPath);
        if ($content === false) {
            return;
        }

        // Requisição TSQ (TimeStamp Query) com hash SHA-256 do PDF
        $hash = hash('sha256', $content, true);
        $tsq = $this->buildTsq($hash);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/timestamp-query\r\nContent-Length: ".strlen($tsq),
                'content' => $tsq,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents(self::TSA_URL, false, $ctx);
        if ($response === false || strlen($response) < 10) {
            return;
        }

        // Sidecar: anexar bytes ao PDF depois de assinado viola o ByteRange e invalida
        // a assinatura. PAdES-T real exige o carimbo dentro do CMS (unsigned attribute),
        // o que o TCPDF não suporta (applyTSA é stub). O .tsr fica como evidência externa.
        file_put_contents($pdfPath.'.tsr', $response);
    }

    private function buildTsq(string $hash): string
    {
        // DER encoding de TimeStampReq (RFC 3161)
        // SEQUENCE { INTEGER 1, MessageImprint { AlgorithmIdentifier SHA-256, OCTET STRING hash }, INTEGER nonce, BOOLEAN TRUE }
        $algoSha256 = "\x30\x0d\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01\x05\x00";
        $msgImprint = "\x30".$this->derLen(strlen($algoSha256) + 2 + 32)
            .$algoSha256
            ."\x04\x20".$hash;

        $nonce = random_bytes(8);
        $nonceInt = "\x02\x08".$nonce;
        $certReq = "\x01\x01\xff";

        $body = "\x02\x01\x01".$msgImprint.$nonceInt.$certReq;

        return "\x30".$this->derLen(strlen($body)).$body;
    }

    private function derLen(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        if ($len < 256) {
            return "\x81".chr($len);
        }

        return "\x82".chr($len >> 8).chr($len & 0xFF);
    }

    // ─── Privados ────────────────────────────────────────────────────────────

    private function newPdf(): Fpdi
    {
        $pdf = new Fpdi(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);

        return $pdf;
    }

    private function normalizePdf(string $path): string
    {
        try {
            $pdf = new Fpdi;
            $pdf->setSourceFile($path);

            return $path;
        } catch (\Throwable $e) {
            $gs = $this->detectGhostscript();
            if ($gs === null) {
                throw new \RuntimeException(
                    'PDF incompatível com FPDI (usa compressão PDF 1.5+). '.
                    'Instale o Ghostscript para conversão automática.'
                );
            }

            $tmp = tempnam(sys_get_temp_dir(), 'pdf_compat_').'.pdf';
            $cmd = escapeshellarg($gs)
                .' -dBATCH -dNOPAUSE -dQUIET -sDEVICE=pdfwrite'
                .' -dCompatibilityLevel=1.4'
                .' -sOutputFile='.escapeshellarg($tmp)
                .' '.escapeshellarg($path)
                .' 2>&1';

            exec($cmd, $output, $code);

            if ($code !== 0 || ! file_exists($tmp) || filesize($tmp) === 0) {
                throw new \RuntimeException('Falha ao converter PDF com Ghostscript. Código: '.$code);
            }

            return $tmp;
        }
    }

    private function detectGhostscript(): ?string
    {
        $candidates = ['gs', 'gswin64c', 'gswin32c'];
        $isWin = DIRECTORY_SEPARATOR === '\\';
        foreach ($candidates as $bin) {
            $cmd = $isWin
                ? 'where '.escapeshellarg($bin).' 2>NUL'
                : 'which '.escapeshellarg($bin).' 2>/dev/null';
            exec($cmd, $out, $code);
            if ($code === 0 && ! empty($out[0])) {
                return trim($out[0]);
            }
        }

        return null;
    }
}
