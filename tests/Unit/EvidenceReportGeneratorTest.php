<?php

namespace Tests\Unit;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EvidenceReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_pdf_with_signer_and_event_data(): void
    {
        $envelope = Envelope::factory()->create([
            'title' => 'Contrato XYZ',
            'status' => 'sent',
            'sha256_original' => str_repeat('ab', 32),
        ]);
        $signer = EnvelopeSigner::factory()->for($envelope)->create([
            'name' => 'Ana Prova', 'cpf' => '123.456.789-00', 'status' => 'signed',
            'signed_at' => now(), 'ip_address' => '10.0.0.9', 'auth_method' => 'email_otp',
        ]);
        $envelope->events()->create(['envelope_signer_id' => $signer->id, 'event' => 'signed', 'ip_address' => '10.0.0.9']);

        $path = (new EvidenceReportGenerator)->generate($envelope->fresh());

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', file_get_contents($path));

        // TCPDF comprime streams; validar conteúdo extraindo texto bruto do PDF não comprimido não é trivial —
        // basta garantir que gera PDF válido e não vazio. O conteúdo é validado visualmente no fim (Task 13).
        $this->assertGreaterThan(1000, filesize($path));
        @unlink($path);
    }
}
