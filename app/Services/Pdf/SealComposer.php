<?php

namespace App\Services\Pdf;

/**
 * Compõe o carimbo visual: selo de autenticação sobreposto à assinatura,
 * ligeiramente acima e à direita dela (efeito de "carimbado").
 *
 * Saída: PNG com fundo transparente em arquivo temporário — usado como
 * imagem principal do stamp (pyHanko background / TCPDF Image). As rubricas
 * das demais páginas continuam usando só a assinatura.
 */
class SealComposer
{
    /** Altura do selo em relação à assinatura e frações de deslocamento. */
    private const SEAL_HEIGHT_RATIO = 0.75;

    private const SEAL_OVERLAP_X = 0.65; // fração do selo que avança além da borda direita da assinatura

    private const SEAL_OVERLAP_Y = 0.55; // fração do selo que sobe acima do topo da assinatura

    /** Retorna caminho de um PNG temporário com a composição assinatura + selo. */
    public static function compose(string $signaturePath, string $sealPath): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('Extensão GD do PHP é necessária para compor o selo de autenticação.');
        }

        $signature = self::open($signaturePath);
        $seal = self::open($sealPath);

        $sigW = imagesx($signature);
        $sigH = imagesy($signature);

        // Selo escalado pela altura da assinatura, preservando proporção
        $sealH = max(1, (int) round($sigH * self::SEAL_HEIGHT_RATIO));
        $sealW = max(1, (int) round(imagesx($seal) * $sealH / imagesy($seal)));

        $canvasW = $sigW + (int) round($sealW * self::SEAL_OVERLAP_X);
        $canvasH = $sigH + (int) round($sealH * self::SEAL_OVERLAP_Y);

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        // Assinatura ancorada embaixo/esquerda; selo no topo/direita, sobrepondo o canto
        imagecopyresampled($canvas, $signature, 0, $canvasH - $sigH, 0, 0, $sigW, $sigH, $sigW, $sigH);
        imagecopyresampled($canvas, $seal, $canvasW - $sealW, 0, 0, 0, $sealW, $sealH, imagesx($seal), imagesy($seal));

        $path = tempnam(sys_get_temp_dir(), 'seal_sig_').'.png';
        imagesavealpha($canvas, true);
        imagepng($canvas, $path);

        imagedestroy($signature);
        imagedestroy($seal);
        imagedestroy($canvas);

        return $path;
    }

    private static function open(string $path)
    {
        $info = @getimagesize($path);
        $img = match ($info['mime'] ?? null) {
            'image/png' => @imagecreatefrompng($path),
            'image/jpeg' => @imagecreatefromjpeg($path),
            default => false,
        };

        if ($img === false) {
            throw new \RuntimeException('Imagem inválida para composição do selo: '.basename($path));
        }

        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        return $img;
    }
}
