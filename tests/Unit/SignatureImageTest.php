<?php

namespace Tests\Unit;

use App\Support\SignatureImage;
use PHPUnit\Framework\TestCase;

class SignatureImageTest extends TestCase
{
    private function pngDataUrl(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    public function test_stores_valid_png_data_url(): void
    {
        $path = SignatureImage::storeDataUrl($this->pngDataUrl());

        $this->assertFileExists($path);
        $this->assertStringStartsWith("\x89PNG", file_get_contents($path));
        @unlink($path);
    }

    public function test_rejects_non_png_prefix(): void
    {
        $this->expectException(\RuntimeException::class);
        SignatureImage::storeDataUrl('data:image/jpeg;base64,'.base64_encode('x'));
    }

    public function test_rejects_invalid_base64_payload(): void
    {
        $this->expectException(\RuntimeException::class);
        SignatureImage::storeDataUrl('data:image/png;base64,not-valid-png');
    }
}
