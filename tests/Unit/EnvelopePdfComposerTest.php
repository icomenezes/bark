<?php

namespace Tests\Unit;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EnvelopePdfComposer;
use App\Services\Envelope\EvidenceReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopePdfComposerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSourcePdf(int $pages = 2): string
    {
        $pdf = new \TCPDF;
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Write(0, "Página {$i} do contrato");
        }
        $path = tempnam(sys_get_temp_dir(), 'src_').'.pdf';
        $pdf->Output($path, 'F');

        return $path;
    }

    private function signaturePng(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_composes_stamped_pdf_plus_evidence_pages(): void
    {
        Storage::fake('documents');

        $envelope = Envelope::factory()->create(['status' => 'sent']);
        Storage::disk('documents')->put("envelopes/{$envelope->id}/original.pdf", file_get_contents($this->makeSourcePdf(2)));
        $envelope->update(['original_pdf_path' => "envelopes/{$envelope->id}/original.pdf"]);

        $signer = EnvelopeSigner::factory()->for($envelope)->create(['status' => 'signed', 'signed_at' => now()]);
        Storage::disk('documents')->put("envelopes/{$envelope->id}/signatures/{$signer->id}.png", $this->signaturePng());
        $signer->update(['signature_image_path' => "envelopes/{$envelope->id}/signatures/{$signer->id}.png"]);
        $signer->fields()->create(['page' => 2, 'x' => 100, 'y' => 600, 'w' => 120, 'h' => 40]);

        $evidence = (new EvidenceReportGenerator)->generate($envelope->fresh());
        $result = (new EnvelopePdfComposer)->compose($envelope->fresh(), $evidence);

        $this->assertFileExists($result['path']);
        $this->assertGreaterThanOrEqual(3, $result['pages']); // 2 do contrato + >=1 de evidências

        // reabre com FPDI para confirmar o page count do resultado
        $check = new \setasign\Fpdi\Tcpdf\Fpdi;
        $this->assertSame($result['pages'], $check->setSourceFile($result['path']));

        @unlink($result['path']);
        @unlink($evidence);
    }

    public function test_download_to_temp_preserves_byte_integrity(): void
    {
        Storage::fake('documents');

        $source = $this->makeSourcePdf(5);
        Storage::disk('documents')->put('envelopes/integrity/original.pdf', file_get_contents($source));

        $composer = new EnvelopePdfComposer;
        $method = new \ReflectionMethod($composer, 'downloadToTemp');
        $method->setAccessible(true);
        $temp = $method->invoke($composer, Storage::disk('documents'), 'envelopes/integrity/original.pdf');

        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $temp));

        @unlink($source);
        @unlink($temp);
    }

    public function test_stamps_verification_footer_on_every_original_page(): void
    {
        Storage::fake('documents');

        $envelope = Envelope::factory()->create([
            'status' => 'sent',
            'verification_code' => '11111111-1111-1111-1111-111111111111',
        ]);
        Storage::disk('documents')->put("envelopes/{$envelope->id}/original.pdf", file_get_contents($this->makeSourcePdf(2)));
        $envelope->update(['original_pdf_path' => "envelopes/{$envelope->id}/original.pdf"]);

        $evidence = (new EvidenceReportGenerator)->generate($envelope->fresh());
        $result = (new EnvelopePdfComposer)->compose($envelope->fresh(), $evidence, compress: false);

        $raw = file_get_contents($result['path']);
        $this->assertStringContainsString('11111111-1111-1111-1111-111111111111', $raw);

        @unlink($result['path']);
        @unlink($evidence);
    }
}
