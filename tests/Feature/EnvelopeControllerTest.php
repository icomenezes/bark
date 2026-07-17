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
                ['name' => 'Ana', 'email' => 'ana@x.com', 'channel' => 'email', 'auth_method' => 'link',
                 'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
            ]),
        ];
    }

    public function test_store_creates_and_sends_envelope(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate();
        $user = User::factory()->withPlan()->create(['role' => 'client']);

        $response = $this->actingAs($user)->post('/envelopes', $this->validPayload());

        $envelope = Envelope::first();
        $response->assertRedirect(route('envelopes.show', $envelope));
        $this->assertSame('sent', $envelope->status);
        $this->assertSame($user->id, $envelope->user_id);
    }

    public function test_store_validates_signers_json(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $this->configurePlatformCertificate();
        $user = User::factory()->withPlan()->create(['role' => 'client']);

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
        Storage::fake('documents');
        Mail::fake();
        $owner = User::factory()->create(['role' => 'client']);
        $envelope = Envelope::factory()->for($owner)->create(['status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'notified']);

        $this->actingAs($owner)->post("/envelopes/{$envelope->id}/remind")->assertRedirect();

        // download antes de completed → 404
        $this->actingAs($owner)->get("/envelopes/{$envelope->id}/download")->assertNotFound();

        Storage::disk('documents')->put("users/{$owner->id}/envelopes/{$envelope->id}/final.pdf", '%PDF-1.4 final');
        $envelope->update(['status' => 'completed', 'final_pdf_path' => "users/{$owner->id}/envelopes/{$envelope->id}/final.pdf"]);
        $this->actingAs($owner)->get("/envelopes/{$envelope->id}/download")->assertRedirect();

        // cancelar só funciona em sent
        $this->actingAs($owner)->post("/envelopes/{$envelope->id}/cancel")->assertSessionHasErrors();
    }

    public function test_index_and_show_render_envelope_data(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $envelope = Envelope::factory()->for($owner)->create(['title' => 'Contrato Visível', 'status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['name' => 'Ana Signatária', 'status' => 'notified']);

        $this->actingAs($owner)->get('/envelopes')->assertOk()->assertSee('Contrato Visível');
        $this->actingAs($owner)->get("/envelopes/{$envelope->id}")
            ->assertOk()->assertSee('Ana Signatária')->assertSee('Aguardando assinaturas');
        $this->actingAs($owner)->get('/envelopes/create')->assertOk()->assertSee('signers_json', false);
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

    public function test_store_blocked_without_plan(): void
    {
        $this->configurePlatformCertificate();
        $user = User::factory()->create(['role' => 'client', 'plan_id' => null]);

        $this->actingAs($user)->post('/envelopes', $this->validPayload())
            ->assertSessionHas('error');

        $this->assertStringContainsString('plano', session('error'));
        $this->assertSame(0, Envelope::count());
    }

    public function test_store_blocked_when_monthly_limit_reached(): void
    {
        $this->configurePlatformCertificate();
        $user = User::factory()->create(['role' => 'client']);
        $user->update(['plan_id' => \App\Models\Plan::factory()->create(['max_envelopes_per_month' => 0])->id]);

        $this->actingAs($user)->post('/envelopes', $this->validPayload())
            ->assertSessionHas('error');

        $this->assertStringContainsString('limite', session('error'));
        $this->assertSame(0, Envelope::count());
    }

    public function test_store_validates_channel_and_auth_method_combination(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $this->configurePlatformCertificate();
        $user = User::factory()->withPlan()->create(['role' => 'client']);

        // whatsapp channel requires whatsapp number
        $noWhatsapp = json_encode([['name' => 'Ana', 'channel' => 'whatsapp', 'auth_method' => 'whatsapp_otp',
            'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]]]);
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $noWhatsapp]))
            ->assertSessionHasErrors('signers_json');

        // email channel with whatsapp_otp is not allowed
        $crossed = json_encode([['name' => 'Ana', 'email' => 'ana@x.com', 'channel' => 'email', 'auth_method' => 'whatsapp_otp',
            'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]]]);
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $crossed]))
            ->assertSessionHasErrors('signers_json');

        // whatsapp channel with email_otp is not allowed
        $crossed2 = json_encode([['name' => 'Ana', 'whatsapp' => '11999998888', 'channel' => 'whatsapp', 'auth_method' => 'email_otp',
            'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]]]);
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $crossed2]))
            ->assertSessionHasErrors('signers_json');
    }

    public function test_store_accepts_whatsapp_channel_signer(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate();
        $user = User::factory()->withPlan()->create(['role' => 'client']);

        $payload = array_merge($this->validPayload(), ['signers_json' => json_encode([
            ['name' => 'Ana', 'whatsapp' => '11999998888', 'channel' => 'whatsapp', 'auth_method' => 'whatsapp_otp',
             'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]],
        ])]);

        $response = $this->actingAs($user)->post('/envelopes', $payload);

        $envelope = Envelope::first();
        $response->assertRedirect(route('envelopes.show', $envelope));
        $this->assertSame('whatsapp', $envelope->signers->first()->channel);
    }
}
