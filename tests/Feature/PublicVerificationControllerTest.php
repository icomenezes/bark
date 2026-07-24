<?php

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\SignedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicVerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_envelope_verification_page(): void
    {
        $envelope = Envelope::factory()->create([
            'title' => 'Contrato de Locação',
            'status' => 'completed',
            'sha256_final' => str_repeat('cd', 32),
            'verification_code' => '66666666-6666-6666-6666-666666666666',
        ]);
        EnvelopeSigner::factory()->for($envelope)->create([
            'name' => 'Fulano de Tal', 'status' => 'signed', 'signed_at' => now(),
            'cpf' => '123.456.789-00', 'ip_address' => '203.0.113.1', 'user_agent' => 'TestAgent/1.0',
        ]);

        $response = $this->get('/verificar/66666666-6666-6666-6666-666666666666');

        $response->assertOk();
        $response->assertSee('Contrato de Locação');
        $response->assertSee('Fulano de Tal');
        $response->assertSee(str_repeat('cd', 32));
        $response->assertDontSee('123.456.789-00');
        $response->assertDontSee('203.0.113.1');
        $response->assertDontSee('TestAgent/1.0');
    }

    public function test_shows_signed_document_verification_page(): void
    {
        SignedDocument::factory()->create([
            'title' => 'Documento avulso',
            'verification_code' => '77777777-7777-7777-7777-777777777777',
            'sha256' => str_repeat('ef', 32),
        ]);

        $response = $this->get('/verificar/77777777-7777-7777-7777-777777777777');

        $response->assertOk();
        $response->assertSee('Documento avulso');
        $response->assertSee(str_repeat('ef', 32));
    }

    public function test_returns_404_for_unknown_code(): void
    {
        $this->get('/verificar/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }
}
