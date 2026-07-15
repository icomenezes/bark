<?php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Models\Certificate;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function configurePlatformCertificate(): void
    {
        $cert = Certificate::factory()->create(['expires_at' => now()->addYear()]);
        Setting::current()->update(['platform_certificate_id' => $cert->id]);
        Setting::clearCache();
    }

    private function validPayload(): array
    {
        return [
            'title' => 'Contrato de Aluguel',
            'message' => 'Favor assinar',
            'signing_order' => 'parallel',
            'pdf' => UploadedFile::fake()->createWithContent('c.pdf', '%PDF-1.4 fake'),
            'signers_json' => json_encode([
                ['name' => 'Ana', 'email' => 'ana@x.com', 'auth_method' => 'link',
                 'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
            ]),
        ];
    }

    public function test_store_creates_and_sends_envelope(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->configurePlatformCertificate();
        $user = User::factory()->create(['role' => 'client']);

        $response = $this->actingAs($user)->post('/envelopes', $this->validPayload());

        $envelope = Envelope::first();
        $response->assertRedirect(route('envelopes.show', $envelope));
        $this->assertSame('sent', $envelope->status);
        $this->assertSame($user->id, $envelope->user_id);
    }

    public function test_store_validates_signers_json(): void
    {
        Storage::fake('local');
        $this->configurePlatformCertificate();
        $user = User::factory()->create(['role' => 'client']);

        // sem signatários
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => '[]']))
            ->assertSessionHasErrors('signers_json');

        // whatsapp_otp sem whatsapp
        $bad = json_encode([['name' => 'Ana', 'email' => 'ana@x.com', 'auth_method' => 'whatsapp_otp',
            'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]]]);
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $bad]))
            ->assertSessionHasErrors('signers_json');

        // signatário sem marcador de assinatura
        $noFields = json_encode([['name' => 'Ana', 'email' => 'ana@x.com', 'auth_method' => 'link', 'fields' => []]]);
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $noFields]))
            ->assertSessionHasErrors('signers_json');
    }

    public function test_show_and_index_only_for_owner(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $envelope = Envelope::factory()->for($owner)->create();

        $this->actingAs($owner)->get("/envelopes/{$envelope->id}")->assertOk();
        $this->actingAs($other)->get("/envelopes/{$envelope->id}")->assertForbidden();
        $this->actingAs($owner)->get('/envelopes')->assertOk();
    }

    public function test_cancel_remind_and_download(): void
    {
        Storage::fake('local');
        Mail::fake();
        $owner = User::factory()->create(['role' => 'client']);
        $envelope = Envelope::factory()->for($owner)->create(['status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'notified']);

        $this->actingAs($owner)->post("/envelopes/{$envelope->id}/remind")->assertRedirect();

        // download antes de completed → 404
        $this->actingAs($owner)->get("/envelopes/{$envelope->id}/download")->assertNotFound();

        Storage::disk('local')->put("signed/envelopes/{$envelope->id}/final.pdf", '%PDF-1.4 final');
        $envelope->update(['status' => 'completed', 'final_pdf_path' => "signed/envelopes/{$envelope->id}/final.pdf"]);
        $this->actingAs($owner)->get("/envelopes/{$envelope->id}/download")->assertOk();

        // cancelar só funciona em sent
        $this->actingAs($owner)->post("/envelopes/{$envelope->id}/cancel")->assertSessionHasErrors();
    }

    public function test_reseal_dispatches_job_when_all_signed(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'client']);
        $envelope = Envelope::factory()->for($owner)->create(['status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'signed', 'signed_at' => now()]);

        $this->actingAs($owner)->post("/envelopes/{$envelope->id}/reseal")->assertRedirect();
        Queue::assertPushed(SealEnvelopeJob::class, 1);
    }
}
