<?php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Mail\Envelopes\EnvelopeCompleted;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeneratesPfx;
use Tests\TestCase;

class SealEnvelopeJobTest extends TestCase
{
    use GeneratesPfx, RefreshDatabase;

    private function makeSourcePdf(): string
    {
        $pdf = new \TCPDF;
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Contrato para lacre');
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

    /** Certificado REAL da plataforma via controller (mesmo approach do SignDocumentTest). */
    private function configureRealPlatformCertificate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/certificates', [
            'description' => 'Cert da plataforma',
            'pfx' => new UploadedFile($this->generatePfx('secret'), 'cert.pfx', 'application/octet-stream', null, true),
            'password' => 'secret',
        ]);
        Setting::current()->update(['platform_certificate_id' => \App\Models\Certificate::latest('id')->first()->id]);
        Setting::clearCache();
        auth()->logout();
    }

    /** Certificado REAL próprio do dono do envelope (não o da plataforma). */
    private function configureOwnersOwnCertificate(User $owner): void
    {
        $this->actingAs($owner)->post('/certificates', [
            'description' => 'Cert próprio',
            'pfx' => new UploadedFile($this->generatePfx('secret'), 'cert.pfx', 'application/octet-stream', null, true),
            'password' => 'secret',
        ]);
        $owner->update(['signing_certificate_id' => \App\Models\Certificate::latest('id')->first()->id]);
        auth()->logout();
    }

    private function makeSignedEnvelope(?User $owner = null): Envelope
    {
        $envelope = Envelope::factory()->when($owner, fn ($f) => $f->for($owner))->create(['status' => 'sent']);
        $originalPath = "users/{$envelope->user_id}/envelopes/{$envelope->id}/original.pdf";
        Storage::disk('documents')->put($originalPath, file_get_contents($this->makeSourcePdf()));
        $envelope->update(['original_pdf_path' => $originalPath]);

        $signer = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'signed', 'signed_at' => now(), 'cpf' => '123.456.789-00',
        ]);
        $signaturePath = "users/{$envelope->user_id}/envelopes/{$envelope->id}/signatures/{$signer->id}.png";
        Storage::disk('documents')->put($signaturePath, $this->signaturePng());
        $signer->update(['signature_image_path' => $signaturePath]);
        $signer->fields()->create(['page' => 1, 'x' => 100, 'y' => 600, 'w' => 120, 'h' => 40]);

        return $envelope->fresh();
    }

    public function test_seals_envelope_end_to_end(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $this->configureRealPlatformCertificate();
        $envelope = $this->makeSignedEnvelope();

        (new SealEnvelopeJob($envelope))->handle(
            app(\App\Services\Envelope\EvidenceReportGenerator::class),
            app(\App\Services\Envelope\EnvelopePdfComposer::class),
            app(\App\Services\Envelope\EnvelopeService::class),
        );

        $envelope->refresh();
        $this->assertSame('completed', $envelope->status);
        $this->assertSame("users/{$envelope->user_id}/envelopes/{$envelope->id}/final.pdf", $envelope->final_pdf_path);
        Storage::disk('documents')->assertExists($envelope->final_pdf_path);
        $this->assertSame(
            hash('sha256', Storage::disk('documents')->get($envelope->final_pdf_path)),
            $envelope->sha256_final
        );
        $this->assertTrue($envelope->events()->where('event', 'sealed')->exists());

        // remetente + 1 signatário
        Mail::assertSent(EnvelopeCompleted::class, 2);
    }

    public function test_failure_records_seal_failed_and_keeps_status(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        // SEM certificado da plataforma → deve falhar
        $envelope = $this->makeSignedEnvelope();

        try {
            (new SealEnvelopeJob($envelope))->handle(
                app(\App\Services\Envelope\EvidenceReportGenerator::class),
                app(\App\Services\Envelope\EnvelopePdfComposer::class),
                app(\App\Services\Envelope\EnvelopeService::class),
            );
            $this->fail('Deveria ter lançado exceção');
        } catch (\Throwable) {
            // esperado
        }

        $envelope->refresh();
        $this->assertSame('sent', $envelope->status);
        $this->assertNull($envelope->final_pdf_path);
        $this->assertTrue($envelope->events()->where('event', 'seal_failed')->exists());
    }

    public function test_seals_using_owners_own_certificate_instead_of_platform(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        // SEM certificado da plataforma configurado — só o certificado próprio do dono existe
        $owner = User::factory()->create(['role' => 'client']);
        $this->configureOwnersOwnCertificate($owner);
        $envelope = $this->makeSignedEnvelope($owner);

        (new SealEnvelopeJob($envelope))->handle(
            app(\App\Services\Envelope\EvidenceReportGenerator::class),
            app(\App\Services\Envelope\EnvelopePdfComposer::class),
            app(\App\Services\Envelope\EnvelopeService::class),
        );

        $envelope->refresh();
        $this->assertSame('completed', $envelope->status);
        Storage::disk('documents')->assertExists($envelope->final_pdf_path);
    }
}
