<?php

namespace Tests\Unit;

use App\Models\EnvelopeSigner;
use App\Services\Envelope\SignerSummary;
use Tests\TestCase;

class SignerSummaryTest extends TestCase
{
    public function test_lists_contact_cpf_and_signature_time(): void
    {
        $signer = new EnvelopeSigner([
            'name' => 'Ana Prova',
            'email' => 'ana@exemplo.com',
            'cpf' => '529.982.247-25',
            'signed_at' => '2026-09-04 14:22:07',
        ]);

        $lines = SignerSummary::lines($signer);

        $this->assertSame('ana@exemplo.com', $lines[0]);
        $this->assertSame('CPF 529.982.247-25', $lines[1]);
        $this->assertSame('Assinou em 04/09/2026 14:22:07', $lines[2]);
    }

    public function test_falls_back_to_whatsapp_when_there_is_no_email(): void
    {
        $signer = new EnvelopeSigner([
            'name' => 'Ana', 'channel' => 'whatsapp', 'whatsapp' => '11988887777',
        ]);

        $this->assertSame('11988887777', SignerSummary::lines($signer)[0]);
    }

    public function test_notes_when_the_cpf_was_checked_against_the_sender(): void
    {
        $signer = new EnvelopeSigner([
            'name' => 'Ana', 'email' => 'ana@exemplo.com',
            'cpf' => '529.982.247-25', 'expected_cpf' => '529.982.247-25',
        ]);

        $this->assertContains('CPF 529.982.247-25 - conferido com o cadastro do remetente', SignerSummary::lines($signer));
    }

    public function test_omits_the_cpf_line_when_there_is_no_cpf(): void
    {
        $signer = new EnvelopeSigner(['name' => 'Ana', 'email' => 'ana@exemplo.com']);

        foreach (SignerSummary::lines($signer) as $line) {
            $this->assertStringNotContainsString('CPF', $line);
        }
    }

    public function test_shows_a_dash_when_not_signed_yet(): void
    {
        $signer = new EnvelopeSigner(['name' => 'Ana', 'email' => 'ana@exemplo.com']);

        $this->assertSame('Assinou em —', SignerSummary::lines($signer)[1]);
    }
}
