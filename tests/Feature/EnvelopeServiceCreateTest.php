<?php

namespace Tests\Feature;

use App\Mail\Envelopes\EnvelopeInvite;
use App\Models\Certificate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopeServiceCreateTest extends TestCase
{
    use RefreshDatabase;

    private function makeEnvelope(User $user, array $overrides = []): \App\Models\Envelope
    {
        $pdf = UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 fake');

        return app(EnvelopeService::class)->create($user, $pdf, array_merge([
            'title' => 'Contrato de Aluguel',
            'message' => 'Assinar até sexta',
            'signing_order' => 'parallel',
            'signers' => [
                ['name' => 'Ana', 'email' => 'ana@x.com', 'channel' => 'email', 'auth_method' => 'link',
                 'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
                ['name' => 'Beto', 'email' => 'beto@x.com', 'channel' => 'email', 'auth_method' => 'email_otp',
                 'fields' => [['page' => 1, 'x' => 300, 'y' => 200, 'w' => 120, 'h' => 40]]],
            ],
        ], $overrides));
    }

    private function configurePlatformCertificate(): void
    {
        $cert = Certificate::factory()->create(['expires_at' => now()->addYear()]);
        Setting::current()->update(['platform_certificate_id' => $cert->id]);
        Setting::clearCache();
    }

    public function test_create_stores_pdf_hash_signers_and_fields(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create(['role' => 'client']);

        $envelope = $this->makeEnvelope($user);

        $this->assertSame('draft', $envelope->status);
        $this->assertSame("users/{$user->id}/envelopes/{$envelope->id}/original.pdf", $envelope->original_pdf_path);
        Storage::disk('documents')->assertExists($envelope->original_pdf_path);
        $this->assertSame(hash('sha256', '%PDF-1.4 fake'), $envelope->sha256_original);
        $this->assertCount(2, $envelope->signers);
        $this->assertSame([1, 2], $envelope->signers->pluck('sign_position')->all());
        $this->assertCount(1, $envelope->signers[0]->fields);
        $this->assertTrue($envelope->events()->where('event', 'created')->exists());
    }

    public function test_send_requires_platform_certificate(): void
    {
        Storage::fake('documents');
        $envelope = $this->makeEnvelope(User::factory()->create(['role' => 'client']));

        $this->expectException(\RuntimeException::class);
        app(EnvelopeService::class)->send($envelope);
    }

    public function test_send_parallel_notifies_all_signers(): void
    {
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate();
        $envelope = $this->makeEnvelope(User::factory()->create(['role' => 'client']));

        app(EnvelopeService::class)->send($envelope);

        $this->assertSame('sent', $envelope->fresh()->status);
        Mail::assertSent(EnvelopeInvite::class, 2);
        $this->assertSame(['notified', 'notified'], $envelope->signers()->pluck('status')->all());
    }

    public function test_send_sequential_notifies_only_first(): void
    {
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate();
        $envelope = $this->makeEnvelope(User::factory()->create(['role' => 'client']), ['signing_order' => 'sequential']);

        app(EnvelopeService::class)->send($envelope);

        Mail::assertSent(EnvelopeInvite::class, 1);
        $this->assertSame(['notified', 'pending'], $envelope->signers()->pluck('status')->all());
    }

    public function test_send_notifies_whatsapp_channel_signer_via_whatsapp_only(): void
    {
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate();
        $envelope = $this->makeEnvelope(User::factory()->create(['role' => 'client']), [
            'signers' => [
                ['name' => 'Ana', 'whatsapp' => '11999998888', 'channel' => 'whatsapp', 'auth_method' => 'whatsapp_otp',
                 'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
            ],
        ]);

        app(EnvelopeService::class)->send($envelope);

        Mail::assertNotSent(EnvelopeInvite::class);
    }

    public function test_send_uses_owners_own_certificate_when_configured(): void
    {
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate(); // certificado da plataforma existe mas não deve ser exigido

        $user = User::factory()->create(['role' => 'client']);
        $ownCert = Certificate::factory()->for($user)->create(['expires_at' => now()->addYear()]);
        $user->update(['signing_certificate_id' => $ownCert->id]);

        $envelope = $this->makeEnvelope($user);

        app(EnvelopeService::class)->send($envelope);

        $this->assertSame('sent', $envelope->fresh()->status);
    }

    public function test_send_fails_when_owner_has_no_certificate_and_no_platform_certificate(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create(['role' => 'client']); // sem signing_certificate_id, sem cert da plataforma
        $envelope = $this->makeEnvelope($user);

        $this->expectException(\RuntimeException::class);
        app(EnvelopeService::class)->send($envelope);
    }

    public function test_create_defaults_send_signed_copy_to_true_when_omitted(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create(['role' => 'client']);
        $envelope = $this->makeEnvelope($user);

        $this->assertTrue($envelope->signers->first()->send_signed_copy);
    }

    public function test_create_respects_send_signed_copy_false(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create(['role' => 'client']);
        $pdf = UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 fake');

        $envelope = app(EnvelopeService::class)->create($user, $pdf, [
            'title' => 'Nota Promissória',
            'signing_order' => 'parallel',
            'signers' => [
                ['name' => 'Ana', 'email' => 'ana@x.com', 'channel' => 'email', 'auth_method' => 'link',
                 'send_signed_copy' => false,
                 'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
            ],
        ]);

        $this->assertFalse($envelope->signers->first()->send_signed_copy);
    }
}
