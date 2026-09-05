<?php

namespace Tests\Unit;

use App\Models\EnvelopeSigner;
use App\Support\ConsentTerm;
use Tests\TestCase;

class ConsentTermTest extends TestCase
{
    public function test_version_is_stable(): void
    {
        $this->assertSame('v1', ConsentTerm::VERSION);
    }

    public function test_text_names_the_email_for_an_email_signer(): void
    {
        $signer = new EnvelopeSigner(['channel' => 'email', 'email' => 'joao@exemplo.com']);

        $text = ConsentTerm::text($signer);

        $this->assertStringContainsString('o e-mail joao@exemplo.com', $text);
        $this->assertStringContainsString('MP 2.200-2/2001', $text);
    }

    public function test_text_names_the_phone_for_a_whatsapp_signer(): void
    {
        $signer = new EnvelopeSigner(['channel' => 'whatsapp', 'whatsapp' => '11988887777']);

        $text = ConsentTerm::text($signer);

        $this->assertStringContainsString('o telefone 11988887777', $text);
        $this->assertStringNotContainsString('e-mail', $text);
    }
}
