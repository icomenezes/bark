<?php

namespace App\Services\Pdf;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Assinatura PAdES via pyHanko (CLI Python, open source, Apache-2.0).
 *
 * Vantagens sobre o motor TCPDF:
 *   - Incremental update: assina sem reescrever o PDF, preservando
 *     assinaturas anteriores (multi-assinatura)
 *   - PAdES B-B/B-T com signingCertificateV2 (exigido pelo validador ITI)
 *   - Carimbo TSA embutido no CMS (PAdES B-T real, não sidecar)
 *
 * Requisito no host: pip install pyHanko pyHanko-cli "pyHanko[image-support]"
 * Override do binário: PYHANKO_BIN (config services.pyhanko.bin)
 */
class PyHankoSigner
{
    // DigiCert: TSA RFC 3161 público e gratuito (timestamp.serpro.gov.br não existe em DNS)
    private const TSA_URL = 'http://timestamp.digicert.com';

    private static ?string $binary = null;

    private static ?string $qpdfBinary = null;

    public static function available(): bool
    {
        return self::binary() !== null;
    }

    public static function isPdfSigned(string $pdfPath): bool
    {
        $content = @file_get_contents($pdfPath);

        return $content !== false && strpos($content, '/ByteRange') !== false;
    }

    /**
     * Assina $pdfIn e grava em $pdfOut via incremental update.
     *
     * @param  array  $position  ['x','y','page','w','h'] em pontos PDF, origem topo-esquerdo
     */
    public function sign(string $pdfIn, string $pdfOut, string $pfxPath, string $password, array $position, bool $useTsa = false, ?string $signImage = null): void
    {
        $bin = self::binary();
        if ($bin === null) {
            throw new \RuntimeException('pyHanko não encontrado. Instale com: pip install pyHanko pyHanko-cli "pyHanko[image-support]" ou defina PYHANKO_BIN.');
        }
        if (! file_exists($pfxPath)) {
            throw new \RuntimeException('Arquivo PFX não encontrado: '.$pfxPath);
        }

        $originalPdfIn = $pdfIn;
        $pdfIn = $this->repairForPyHanko($pdfIn);
        $repairedTemp = $pdfIn !== $originalPdfIn ? $pdfIn : null;

        // Valida a senha antes: o erro da CLI para PFX inválido é genérico.
        // PFX legado (RC2/3DES) é convertido e a CLI recebe o arquivo moderno.
        $reader = new Pkcs12Reader;
        $tempPfx = null;
        if ($reader->read((string) file_get_contents($pfxPath), $password) === null) {
            throw new \RuntimeException($reader->wasWrongPassword()
                ? 'Falha ao ler PFX: senha incorreta.'
                : 'Falha ao ler PFX: formato legado não suportado ('.implode(' | ', $reader->errors()).')');
        }
        if ($reader->wasConverted()) {
            $tempPfx = (string) tempnam(sys_get_temp_dir(), 'pfx_mod_');
            file_put_contents($tempPfx, $reader->normalizedContent());
            $pfxPath = $tempPfx;
        }

        $field = $this->fieldSpec($pdfIn, $position);

        $passfile = (string) tempnam(sys_get_temp_dir(), 'pyh_pass_');
        file_put_contents($passfile, $password);

        $configYml = null;
        $hasStyle = $signImage && file_exists($signImage);
        if ($hasStyle) {
            $configYml = tempnam(sys_get_temp_dir(), 'pyh_cfg_').'.yml';
            file_put_contents($configYml, $this->styleConfig($signImage));
        }

        $cmd = escapeshellarg($bin);
        if ($configYml) {
            $cmd .= ' --config '.escapeshellarg($configYml);
        }
        $cmd .= ' sign addsig --use-pades --field '.escapeshellarg($field);
        if ($hasStyle) {
            $cmd .= ' --style-name appstamp';
        }
        if ($useTsa) {
            $cmd .= ' --timestamp-url '.escapeshellarg(self::TSA_URL);
        }
        $cmd .= ' pkcs12 --passfile '.escapeshellarg($passfile)
            .' '.escapeshellarg($pdfIn)
            .' '.escapeshellarg($pdfOut)
            .' '.escapeshellarg($pfxPath)
            .' 2>&1';

        exec($cmd, $output, $code);

        @unlink($passfile);
        if ($configYml) {
            @unlink($configYml);
        }
        if ($tempPfx) {
            @unlink($tempPfx);
        }
        if ($repairedTemp) {
            @unlink($repairedTemp);
        }

        if ($code !== 0 || ! file_exists($pdfOut) || filesize($pdfOut) === 0) {
            throw new \RuntimeException('pyHanko falhou (código '.$code.'): '.implode(' | ', array_slice($output, -5)));
        }
    }

    /**
     * O TCPDF/FPDI reescreve o PDF ao estampar rubricas e o resultado, embora
     * abra normalmente em leitores tolerantes, às vezes sai com a tabela xref
     * mal formada para o parser estrito do pyHanko ("Could not find xref table
     * at specified location"). O qpdf reescreve/repara essa estrutura antes da
     * assinatura. Sem qpdf disponível, segue com o arquivo original — pyHanko
     * ainda pode lê-lo se ele não tiver esse defeito.
     */
    private function repairForPyHanko(string $pdfIn): string
    {
        // PDF já assinado: reescrever a estrutura via qpdf quebra a atualização
        // incremental e invalida a cobertura de bytes da(s) assinatura(s) anterior(es).
        if (self::isPdfSigned($pdfIn)) {
            return $pdfIn;
        }

        $qpdf = self::detectQpdf();
        if ($qpdf === null) {
            return $pdfIn;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pyh_repaired_').'.pdf';
        $cmd = escapeshellarg($qpdf).' '.escapeshellarg($pdfIn).' '.escapeshellarg($tmp).' 2>&1';
        exec($cmd, $output, $code);

        if ($code !== 0 || ! file_exists($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);

            return $pdfIn;
        }

        return $tmp;
    }

    /** Mesma lógica de "não cacheia negativo" de binary(), aplicada ao qpdf. */
    private static function detectQpdf(): ?string
    {
        if (self::$qpdfBinary !== null) {
            return self::$qpdfBinary;
        }

        $configured = config('services.qpdf.bin') ?: getenv('QPDF_BIN');
        if ($configured && file_exists($configured)) {
            return self::$qpdfBinary = $configured;
        }

        $isWin = DIRECTORY_SEPARATOR === '\\';
        $cmd = $isWin ? 'where qpdf 2>NUL' : 'which qpdf 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code === 0 && ! empty($out[0]) && file_exists(trim($out[0]))) {
            return self::$qpdfBinary = trim($out[0]);
        }

        if ($isWin) {
            $glob = glob('C:/Program Files/qpdf */bin/qpdf.exe') ?: [];
            if (! empty($glob[0])) {
                return self::$qpdfBinary = $glob[0];
            }
        }

        return null;
    }

    /**
     * Converte posição em pontos topo-esquerdo para o retângulo do campo
     * de assinatura no espaço PDF (origem base-esquerda): y1 = alturaPagina − y − h.
     */
    private function fieldSpec(string $pdfIn, array $position): string
    {
        $page = max(1, (int) ($position['page'] ?? 1));
        $x = (float) ($position['x'] ?? 150);
        $y = (float) ($position['y'] ?? 240);
        $w = max(5.0, (float) ($position['w'] ?? 50));
        $h = max(5.0, (float) ($position['h'] ?? 25));

        [$pageW, $pageH] = $this->pageSizePt($pdfIn, $page);

        $x1 = max(0.0, min($x, max(0.0, $pageW - $w)));
        $yTop = max(0.0, min($y, max(0.0, $pageH - $h)));
        $y1 = $pageH - $yTop - $h;

        return sprintf(
            '%d/%d,%d,%d,%d/Assinatura_%s',
            $page,
            (int) round($x1),
            (int) round($y1),
            (int) round($x1 + $w),
            (int) round($y1 + $h),
            bin2hex(random_bytes(4))
        );
    }

    private function pageSizePt(string $pdfPath, int $page): array
    {
        try {
            $fpdi = new Fpdi('P', 'pt', 'A4', true, 'UTF-8', false);
            $total = $fpdi->setSourceFile($pdfPath);
            $tpl = $fpdi->importPage(min(max(1, $page), max(1, $total)));
            $size = $fpdi->getTemplateSize($tpl);

            return [(float) $size['width'], (float) $size['height']];
        } catch (\Throwable $e) {
            // FPDI livre não lê alguns PDFs (xref stream); A4 retrato como fallback
            return [595.28, 841.89];
        }
    }

    private function styleConfig(string $signImage): string
    {
        $img = str_replace('\\', '/', realpath($signImage) ?: $signImage);

        // border-width 0: sem a moldura preta padrão do stamp do pyHanko
        return "stamp-styles:\n"
            ."    appstamp:\n"
            ."        type: text\n"
            ."        stamp-text: \"\"\n"
            ."        border-width: 0\n"
            ."        background: \"{$img}\"\n"
            ."        background-opacity: 1\n";
    }

    /**
     * Não cacheia resultado negativo: workers php-fpm vivem dias sem reciclar,
     * então "não encontrado" preso em propriedade static ficaria permanente
     * mesmo depois do binário ser instalado. Só cacheia quando encontrado.
     */
    private static function binary(): ?string
    {
        if (self::$binary !== null) {
            return self::$binary;
        }

        $configured = config('services.pyhanko.bin') ?: getenv('PYHANKO_BIN');
        if ($configured && file_exists($configured)) {
            return self::$binary = $configured;
        }

        $isWin = DIRECTORY_SEPARATOR === '\\';

        if (! $isWin) {
            foreach (['/usr/local/bin/pyhanko', '/opt/pyhanko-venv/bin/pyhanko'] as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return self::$binary = $candidate;
                }
            }
        }

        $cmd = $isWin ? 'where pyhanko 2>NUL' : 'which pyhanko 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code === 0 && ! empty($out[0]) && file_exists(trim($out[0]))) {
            return self::$binary = trim($out[0]);
        }

        if ($isWin) {
            $glob = glob((getenv('LOCALAPPDATA') ?: '').'/Programs/Python/*/Scripts/pyhanko.exe') ?: [];
            if (! empty($glob[0])) {
                return self::$binary = $glob[0];
            }
        }

        return null;
    }
}
