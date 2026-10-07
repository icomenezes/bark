<?php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Jobs\SendEnvelopeWebhookJob;
use App\Mail\Envelopes\EnvelopeCompleted;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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

    /** Roda o job resolvendo as dependências do handle() pelo container. */
    private function seal(Envelope $envelope): void
    {
        app()->call([new SealEnvelopeJob($envelope), 'handle']);
    }

    public function test_sealing_an_api_envelope_dispatches_the_signed_webhook(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        Queue::fake([SendEnvelopeWebhookJob::class]);
        $this->configureRealPlatformCertificate();
        $owner = User::factory()->create(['webhook_url' => 'https://ponto.example.com/hook']);
        $envelope = $this->makeSignedEnvelope($owner);
        $envelope->update(['source' => 'api']);

        $this->seal($envelope);

        $this->assertSame('completed', $envelope->fresh()->status);
        Queue::assertPushed(SendEnvelopeWebhookJob::class, fn ($job) => $job->envelopeId === $envelope->id
            && $job->event === 'envelope.signed');
    }

    public function test_sealing_notifies_whatsapp_signers_with_the_document_link(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        Http::fake();
        config(['services.evolution.url' => 'https://evo.test', 'services.evolution.instance' => 'i', 'services.evolution.key' => 'k']);
        Setting::current()->update(['whatsapp_enabled' => true]);
        Setting::clearCache();
        $this->configureRealPlatformCertificate();
        $owner = User::factory()->create(['whatsapp_envelope_enabled' => true]);
        $envelope = $this->makeSignedEnvelope($owner);
        $whatsappSigner = $envelope->signers->first();
        $whatsappSigner->update(['channel' => 'whatsapp', 'email' => null, 'whatsapp' => '11999998888']);
        $emailSigner = EnvelopeSigner::factory()->for($envelope)->create([
            'channel' => 'email', 'email' => 'copia@example.com', 'whatsapp' => '11977776666',
            'status' => 'signed', 'signed_at' => now(), 'sign_position' => 2,
        ]);

        $this->seal($envelope);

        // O aviso apontava para uma rota inexistente: a exceção gravava seal_failed e cortava o loop.
        $this->assertFalse($envelope->events()->where('event', 'seal_failed')->exists());
        foreach ([$whatsappSigner, $emailSigner] as $signer) {
            Http::assertSent(fn ($request) => str_contains($request['number'] ?? '', $signer->whatsapp)
                && str_contains($request['text'] ?? '', route('public.sign.document', $signer->token)));
        }
        Mail::assertSent(EnvelopeCompleted::class, fn ($m) => $m->hasTo('copia@example.com'));
    }

    public function test_seals_envelope_end_to_end(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $this->configureRealPlatformCertificate();
        $envelope = $this->makeSignedEnvelope();

        $this->seal($envelope);

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
            $this->seal($envelope);
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

        $this->seal($envelope);

        $envelope->refresh();
        $this->assertSame('completed', $envelope->status);
        Storage::disk('documents')->assertExists($envelope->final_pdf_path);
    }

    public function test_skips_completion_email_for_signer_with_send_signed_copy_false(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $this->configureRealPlatformCertificate();
        $envelope = $this->makeSignedEnvelope();
        $envelope->signers()->update(['send_signed_copy' => false]);

        $this->seal($envelope);

        // só o remetente recebe — o único signatário tem send_signed_copy=false
        Mail::assertSent(EnvelopeCompleted::class, 1);
    }
}
