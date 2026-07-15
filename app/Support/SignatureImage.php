<?php

namespace App\Support;

class SignatureImage
{
    /** Decodifica data-URL PNG (pad de assinatura), valida e grava em arquivo temporário. */
    public static function storeDataUrl(string $dataUrl): string
    {
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw new \RuntimeException('Assinatura desenhada inválida: desenhe novamente.');
        }

        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        // Magic bytes PNG + limite de 2 MB decodificados
        if ($binary === false || strlen($binary) > 2 * 1024 * 1024 || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new \RuntimeException('Assinatura desenhada inválida: desenhe novamente.');
        }
        if (getimagesizefromstring($binary) === false) {
            throw new \RuntimeException('Assinatura desenhada inválida: desenhe novamente.');
        }

        $path = tempnam(sys_get_temp_dir(), 'drawn_sig_').'.png';
        file_put_contents($path, $binary);

        return $path;
    }
}
