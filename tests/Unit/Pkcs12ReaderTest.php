<?php

namespace Tests\Unit;

use App\Services\Pdf\Pkcs12Reader;
use Tests\Concerns\GeneratesPfx;
use Tests\TestCase;

class Pkcs12ReaderTest extends TestCase
{
    use GeneratesPfx;

    /** PFX cifrado com RC2-40 (OpenSSL 0.9.8, mesmo formato de A1 antigos de ACs brasileiras). */
    private function legacyPfxContent(): string
    {
        return file_get_contents(base_path('tests/Fixtures/legacy-rc2-40.pfx'));
    }

    public function test_reads_modern_pfx_natively(): void
    {
        $content = file_get_contents($this->generatePfx('secret'));

        $reader = new Pkcs12Reader;
        $p12 = $reader->read($content, 'secret');

        $this->assertIsArray($p12);
        $this->assertArrayHasKey('cert', $p12);
        $this->assertFalse($reader->wasConverted());
        $this->assertSame($content, $reader->normalizedContent());
    }

    public function test_wrong_password_on_modern_pfx_is_classified(): void
    {
        $content = file_get_contents($this->generatePfx('senha-certa'));

        $reader = new Pkcs12Reader;
        $this->assertNull($reader->read($content, 'senha-errada'));
        $this->assertTrue($reader->wasWrongPassword());
    }

    public function test_legacy_pfx_with_correct_password_is_converted(): void
    {
        $reader = new Pkcs12Reader;

        // Sanidade: nativo realmente falha (OpenSSL 3 sem RC2-40) — se um dia
        // o provider legacy for habilitado no PHP, o read nativo resolve sozinho
        $p12 = $reader->read($this->legacyPfxContent(), 'secret');

        if ($p12 === null && ! $reader->conversionAvailable()) {
            $this->markTestSkipped('Nenhum CLI openssl capaz de ler PFX legado disponível nesta máquina.');
        }

        $this->assertIsArray($p12, 'PFX legado com senha correta deve ser lido via conversão');
        $this->assertArrayHasKey('cert', $p12);
        $this->assertFalse($reader->wasWrongPassword());

        // O conteúdo normalizado precisa ser legível pelo OpenSSL 3 nativo
        $native = [];
        $this->assertTrue(
            openssl_pkcs12_read($reader->normalizedContent(), $native, 'secret'),
            'Conteúdo convertido deve ser um PFX moderno legível nativamente'
        );
    }

    public function test_legacy_pfx_with_wrong_password_is_classified_as_wrong_password(): void
    {
        $reader = new Pkcs12Reader;

        if (! $reader->conversionAvailable()) {
            $this->markTestSkipped('Nenhum CLI openssl capaz de ler PFX legado disponível nesta máquina.');
        }

        $this->assertNull($reader->read($this->legacyPfxContent(), 'senha-errada'));
        $this->assertTrue($reader->wasWrongPassword());
    }
}
