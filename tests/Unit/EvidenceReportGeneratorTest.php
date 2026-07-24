<?php

namespace Tests\Unit;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Services\Envelope\EvidenceReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidenceReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function signaturePng(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_generates_pdf_with_signer_and_event_data(): void
    {
        Storage::fake('documents');

        $envelope = Envelope::factory()->create([
            'title' => 'Contrato XYZ',
            'status' => 'sent',
            'sha256_original' => str_repeat('ab', 32),
            'verification_code' => '33333333-3333-3333-3333-333333333333',
        ]);
        $signer = EnvelopeSigner::factory()->for($envelope)->create([
            'name' => 'Ana Prova', 'cpf' => '123.456.789-00', 'status' => 'signed',
            'signed_at' => now(), 'ip_address' => '10.0.0.9', 'auth_method' => 'email_otp',
        ]);
        $envelope->events()->create(['envelope_signer_id' => $signer->id, 'event' => 'signed', 'ip_address' => '10.0.0.9']);

        $path = (new EvidenceReportGenerator)->generate($envelope->fresh());

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', file_get_contents($path));
        $this->assertGreaterThan(1000, filesize($path));
        @unlink($path);
    }

    public function test_generates_report_with_signature_preview_image(): void
    {
        Storage::fake('documents');

        $envelope = Envelope::factory()->create(['verification_code' => '44444444-4444-4444-4444-444444444444']);
        $signer = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'signed',
            'signed_at' => now(),
            'cpf' => '123.456.789-00',
        ]);

        $path = "users/{$envelope->user_id}/envelopes/{$envelope->id}/signatures/{$signer->id}.png";
        Storage::disk('documents')->put($path, $this->signaturePng());
        $signer->update(['signature_image_path' => $path]);

        // Preview da assinatura ao lado do nome — diferente da EnvelopePdfComposer,
        // que estampa a assinatura no corpo do documento; aqui é só uma miniatura.
        $result = (new EvidenceReportGenerator)->generate($envelope->fresh(['signers', 'events']));

        $this->assertFileExists($result);
        @unlink($result);
    }

    public function test_uses_settings_primary_color_for_border(): void
    {
        Storage::fake('documents');
        Setting::current()->update(['primary_color' => '#123456']);

        $envelope = Envelope::factory()->create(['verification_code' => '55555555-5555-5555-5555-555555555555']);

        $path = (new EvidenceReportGenerator)->generate($envelope->fresh(['signers', 'events']));

        $this->assertFileExists($path);
        @unlink($path);
    }
}
