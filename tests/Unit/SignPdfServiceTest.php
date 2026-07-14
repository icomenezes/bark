<?php

namespace Tests\Unit;

use App\Services\Pdf\PyHankoSigner;
use App\Services\Pdf\SignPdfService;
use Tests\Concerns\GeneratesPfx;
use Tests\TestCase;

class SignPdfServiceTest extends TestCase
{
    use GeneratesPfx;

    public function test_create_sign_save_round_trip_produces_signed_pdf(): void
    {
        $pfx = $this->generatePfx('secret');
        $out = tempnam(sys_get_temp_dir(), 'signed_').'.pdf';

        $svc = new SignPdfService;
        $svc->loadPfxCertificate($pfx, 'secret');
        $svc->createPdf(['title' => 'TESTE', 'subtitle' => 'Round trip'], '<p>Conteúdo de teste</p>')
            ->sign(true, ['x' => 150, 'y' => 240, 'w' => 150, 'h' => 60, 'page' => 1])
            ->save($out);

        $this->assertFileExists($out);
        $this->assertGreaterThan(0, filesize($out));
        $this->assertTrue(PyHankoSigner::isPdfSigned($out), 'PDF salvo deve conter /ByteRange');
    }

    public function test_unsigned_pdf_is_not_detected_as_signed(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'plain_').'.pdf';

        (new SignPdfService)
            ->createPdf([], '<p>Sem assinatura</p>')
            ->save($out);

        $this->assertFalse(PyHankoSigner::isPdfSigned($out));
    }

    public function test_wrong_pfx_password_throws(): void
    {
        $pfx = $this->generatePfx('certa');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('senha incorreta');

        (new SignPdfService)->loadPfxCertificate($pfx, 'errada');
    }
}
