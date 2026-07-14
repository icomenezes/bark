<?php

namespace Tests\Unit;

use App\Services\Pdf\SealComposer;
use Tests\TestCase;

class SealComposerTest extends TestCase
{
    private function makePng(int $w, int $h, array $rgb): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), $w - 4, $h - 4, imagecolorallocate($img, ...$rgb));
        imagesavealpha($img, true);

        $path = tempnam(sys_get_temp_dir(), 'img_').'.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    public function test_composes_seal_above_right_of_signature(): void
    {
        $signature = $this->makePng(200, 80, [30, 58, 138]);
        $seal = $this->makePng(100, 100, [220, 38, 38]);

        $out = SealComposer::compose($signature, $seal);

        $this->assertFileExists($out);
        [$w, $h, $type] = getimagesize($out);
        $this->assertSame(IMAGETYPE_PNG, $type);

        // Canvas cresce para acomodar o selo acima e à direita da assinatura
        $this->assertGreaterThan(200, $w);
        $this->assertGreaterThan(80, $h);
    }

    public function test_rejects_invalid_image(): void
    {
        $bogus = tempnam(sys_get_temp_dir(), 'bad_').'.png';
        file_put_contents($bogus, 'não é png');

        $this->expectException(\RuntimeException::class);

        SealComposer::compose($bogus, $bogus);
    }
}
