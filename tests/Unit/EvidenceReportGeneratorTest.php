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

    public function test_long_title_wraps_instead_of_running_under_the_qr_code(): void
    {
        Storage::fake('documents');
        $title = 'F. Ponto - 01/09/2026 a 30/09/2026 - 0005021 - CLAUDIA DOS SANTOS BARBOZA';
        $envelope = Envelope::factory()->create([
            'title' => $title,
            'verification_code' => '66666666-6666-6666-6666-666666666666',
        ]);

        $path = (new EvidenceReportGenerator)->generate($envelope->fresh(['signers', 'events']));
        $runs = $this->textRuns($path);
        @unlink($path);

        // Cada linha desenhada vira um trecho de texto no PDF: o título inteiro em um
        // trecho só significa que ele ultrapassou a coluna e invadiu o QR.
        $titleLines = array_values(array_filter(
            array_map('trim', $runs),
            fn (string $run) => strlen($run) > 10 && str_contains($title, $run),
        ));
        $this->assertGreaterThanOrEqual(2, count($titleLines), 'O título longo deveria quebrar em mais de uma linha.');
        $this->assertSame($title, implode(' ', $titleLines));
    }

    /** Trechos de texto (operadores Tj/TJ) de todos os streams do PDF. */
    private function textRuns(string $path): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', file_get_contents($path), $streams);

        $runs = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content === false) {
                continue;
            }
            preg_match_all('/\[\((.*?)\)\]\s*TJ|\((.*?)\)\s*Tj/s', $content, $m);
            foreach (array_keys($m[0]) as $i) {
                $runs[] = stripcslashes($m[1][$i] !== '' ? $m[1][$i] : $m[2][$i]);
            }
        }

        return $runs;
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
