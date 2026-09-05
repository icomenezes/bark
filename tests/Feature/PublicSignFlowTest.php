<?php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Mail\Envelopes\EnvelopeOtp;
use App\Mail\Envelopes\EnvelopeSignerLocked;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicSignFlowTest extends TestCase
{
    use RefreshDatabase;

    private function pngDataUrl(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    private function makeSentEnvelope(array $signerAttrs = []): EnvelopeSigner
    {
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $path = "users/{$envelope->user_id}/envelopes/{$envelope->id}/original.pdf";
        Storage::disk('documents')->put($path, '%PDF-1.4 fake');
        $envelope->update(['original_pdf_path' => $path]);

        return EnvelopeSigner::factory()->for($envelope)->create(array_merge(['status' => 'notified'], $signerAttrs));
    }

    private function signPayload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Ana Completa', 'cpf' => '123.456.789-09',
            'signature_type' => 'drawn', 'signature' => $this->pngDataUrl(),
            'consent' => '1',
        ], $extra);
    }

    public function test_show_marks_viewed_and_renders(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $signer = $this->makeSentEnvelope();

        $this->get("/sign/{$signer->token}")
            ->assertOk()
            ->assertSee($signer->envelope->title);

        $this->assertSame('viewed', $signer->fresh()->status);
        $this->assertTrue($signer->envelope->events()->where('event', 'viewed')->exists());
    }

    public function test_owner_preview_does_not_forge_a_viewed_event(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $signer = $this->makeSentEnvelope();
        $owner = $signer->envelope->user;

        $this->actingAs($owner)->get("/sign/{$signer->token}")->assertOk();

        $this->assertSame('notified', $signer->fresh()->status);
        $this->assertFalse($signer->events()->where('event', 'viewed')->exists());
        $this->assertTrue($signer->events()->where('event', 'owner_previewed')->exists());
    }

    public function test_another_logged_in_user_is_treated_as_a_regular_visitor(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $signer = $this->makeSentEnvelope();
        $stranger = User::factory()->create(['role' => 'client']);

        $this->actingAs($stranger)->get("/sign/{$signer->token}")->assertOk();

        $this->assertSame('viewed', $signer->fresh()->status);
        $this->assertTrue($signer->events()->where('event', 'viewed')->exists());
        $this->assertFalse($signer->events()->where('event', 'owner_previewed')->exists());
    }

    public function test_owner_preview_does_not_block_the_real_signer_later(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope();

        $this->actingAs($signer->envelope->user)->get("/sign/{$signer->token}")->assertOk();

        auth()->logout();
        $this->get("/sign/{$signer->token}")->assertOk();
        $this->assertSame('viewed', $signer->fresh()->status);

        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Documento assinado com sucesso');
    }

    public function test_invalid_token_and_unavailable_states(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $this->get('/sign/'.str_repeat('x', 64))->assertNotFound();

        $signer = $this->makeSentEnvelope();
        $signer->envelope->update(['status' => 'cancelled']);
        $this->get("/sign/{$signer->token}")->assertOk()->assertSee('não está mais disponível');

        $expired = $this->makeSentEnvelope();
        $expired->envelope->update(['status' => 'sent', 'expires_at' => now()->subDay()]);
        $this->get("/sign/{$expired->token}")->assertOk()->assertSee('expirou');
    }

    public function test_link_signer_signs_without_otp(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['auth_method' => 'link']);

        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Documento assinado com sucesso');

        $signer->refresh();
        $this->assertSame('signed', $signer->status);
        Queue::assertPushed(SealEnvelopeJob::class, 1); // único signatário → lacre
    }

    public function test_single_signer_envelope_shows_completion_message(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['auth_method' => 'link']);

        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Documento assinado com sucesso')
            ->assertDontSee('Quando todos assinarem');

        Queue::assertPushed(SealEnvelopeJob::class, 1);
    }

    public function test_multi_signer_envelope_mentions_email_when_pending(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $path = "users/{$envelope->user_id}/envelopes/{$envelope->id}/original.pdf";
        Storage::disk('documents')->put($path, '%PDF-1.4 fake');
        $envelope->update(['original_pdf_path' => $path]);

        $first = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'notified', 'auth_method' => 'link', 'channel' => 'email', 'sign_position' => 1,
        ]);
        EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'pending', 'auth_method' => 'link', 'channel' => 'email', 'sign_position' => 2,
        ]);

        $this->post("/sign/{$first->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Assinatura registrada')
            ->assertSee('por e-mail')
            ->assertDontSee('Documento assinado com sucesso');

        Queue::assertNotPushed(SealEnvelopeJob::class);
    }

    public function test_multi_signer_envelope_mentions_whatsapp_when_pending(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $path = "users/{$envelope->user_id}/envelopes/{$envelope->id}/original.pdf";
        Storage::disk('documents')->put($path, '%PDF-1.4 fake');
        $envelope->update(['original_pdf_path' => $path]);

        $first = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'notified', 'auth_method' => 'link', 'channel' => 'whatsapp',
            'whatsapp' => '11999998888', 'sign_position' => 1,
        ]);
        EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'pending', 'auth_method' => 'link', 'channel' => 'email', 'sign_position' => 2,
        ]);

        $this->post("/sign/{$first->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Assinatura registrada')
            ->assertSee('por WhatsApp');
    }

    public function test_otp_signer_requires_valid_code(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['auth_method' => 'email_otp']);

        // sem código → erro de validação
        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertSessionHasErrors('otp_code');

        // solicita o código
        $this->post("/sign/{$signer->token}/otp")->assertRedirect();
        Mail::assertSent(EnvelopeOtp::class, function (EnvelopeOtp $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        // código errado
        $this->post("/sign/{$signer->token}", $this->signPayload(['otp_code' => '000000']))
            ->assertSessionHasErrors('otp_code');
        $this->assertSame('notified', $signer->fresh()->status);

        // código certo
        $this->post("/sign/{$signer->token}", $this->signPayload(['otp_code' => $code]))->assertOk();
        $this->assertSame('signed', $signer->fresh()->status);
    }

    public function test_signed_signer_cannot_sign_again(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['auth_method' => 'link']);

        $this->post("/sign/{$signer->token}", $this->signPayload())->assertOk();
        $this->post("/sign/{$signer->token}", $this->signPayload())->assertOk()
            ->assertSee('já assinou');
        $this->get("/sign/{$signer->token}")->assertOk()->assertSee('já assinou');
    }

    public function test_decline_needs_reason_and_ends_envelope(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $signer = $this->makeSentEnvelope();

        $this->post("/sign/{$signer->token}/decline", [])->assertSessionHasErrors('reason');

        $this->post("/sign/{$signer->token}/decline", ['reason' => 'Não concordo'])
            ->assertOk()->assertSee('recusa foi registrada');

        $this->assertSame('declined', $signer->envelope->fresh()->status);
    }

    public function test_document_serves_original_then_final(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $signer = $this->makeSentEnvelope();

        $this->get("/sign/{$signer->token}/document")->assertRedirect();

        $finalPath = "users/{$signer->envelope->user_id}/envelopes/{$signer->envelope_id}/final.pdf";
        Storage::disk('documents')->put($finalPath, '%PDF-1.4 final');
        $signer->envelope->update(['status' => 'completed', 'final_pdf_path' => $finalPath]);

        $this->get("/sign/{$signer->token}/document")->assertRedirect();
    }

    // ─── Termo de aceite ──────────────────────────────────────────────────────

    public function test_signing_without_accepting_the_term_is_rejected(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $signer = $this->makeSentEnvelope();

        $payload = $this->signPayload();
        unset($payload['consent']);

        $this->post("/sign/{$signer->token}", $payload)->assertSessionHasErrors('consent');

        $this->assertNotSame('signed', $signer->fresh()->status);
    }

    public function test_accepting_the_term_is_recorded_on_the_signer_and_the_trail(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope();

        $this->post("/sign/{$signer->token}", $this->signPayload())->assertOk();

        $signer->refresh();
        $this->assertNotNull($signer->consent_accepted_at);
        $this->assertSame('v1', $signer->consent_version);

        $event = $signer->events()->where('event', 'consent_accepted')->first();
        $this->assertNotNull($event);
        $this->assertSame('v1', $event->meta['version']);
    }

    public function test_consent_event_is_recorded_before_the_signature_event(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope();

        $this->post("/sign/{$signer->token}", $this->signPayload())->assertOk();

        $consentId = $signer->events()->where('event', 'consent_accepted')->value('id');
        $signedId = $signer->events()->where('event', 'signed')->value('id');

        $this->assertLessThan($signedId, $consentId);
    }

    public function test_sign_screen_shows_the_term_naming_the_channel(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $signer = $this->makeSentEnvelope(['channel' => 'email', 'email' => 'joao@exemplo.com']);

        $this->get("/sign/{$signer->token}")
            ->assertOk()
            ->assertSee('o e-mail joao@exemplo.com', false);
    }

    // ─── Conferência de CPF ───────────────────────────────────────────────────

    public function test_cpf_with_invalid_check_digits_is_rejected(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $signer = $this->makeSentEnvelope();

        $this->post("/sign/{$signer->token}", $this->signPayload(['cpf' => '111.111.111-11']))
            ->assertSessionHasErrors('cpf');

        $this->assertNotSame('signed', $signer->fresh()->status);
    }

    public function test_cpf_divergent_from_expected_blocks_and_records_a_masked_event(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $signer = $this->makeSentEnvelope(['expected_cpf' => '529.982.247-25']);

        $this->post("/sign/{$signer->token}", $this->signPayload(['cpf' => '111.444.777-35']))
            ->assertSessionHasErrors('cpf');

        $this->assertNotSame('signed', $signer->fresh()->status);

        $event = $signer->events()->where('event', 'cpf_mismatch')->first();
        $this->assertNotNull($event);
        $this->assertSame('***.444.777-**', $event->meta['attempted']);
    }

    public function test_matching_cpf_signs_regardless_of_formatting(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['expected_cpf' => '529.982.247-25']);

        $this->post("/sign/{$signer->token}", $this->signPayload(['cpf' => '52998224725']))
            ->assertOk()
            ->assertSee('Documento assinado com sucesso');

        $this->assertSame('signed', $signer->fresh()->status);
    }

    public function test_fifth_mismatch_locks_the_link_and_notifies_the_sender(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $signer = $this->makeSentEnvelope(['expected_cpf' => '529.982.247-25']);

        foreach (range(1, 5) as $ignored) {
            $this->post("/sign/{$signer->token}", $this->signPayload(['cpf' => '111.444.777-35']));
        }

        $this->assertTrue($signer->fresh()->isCpfLocked());
        $this->assertTrue($signer->events()->where('event', 'cpf_locked')->exists());
        Mail::assertSent(EnvelopeSignerLocked::class, 1);

        $this->get("/sign/{$signer->token}")->assertOk()->assertSee('bloqueado');
    }

    public function test_locked_link_refuses_even_the_correct_cpf(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $signer = $this->makeSentEnvelope(['expected_cpf' => '529.982.247-25']);

        foreach (range(1, 5) as $ignored) {
            $this->post("/sign/{$signer->token}", $this->signPayload(['cpf' => '111.444.777-35']));
        }

        $this->post("/sign/{$signer->token}", $this->signPayload(['cpf' => '529.982.247-25']))
            ->assertOk()
            ->assertSee('bloqueado');

        $this->assertNotSame('signed', $signer->fresh()->status);
    }

    public function test_mismatch_is_only_counted_after_a_valid_otp(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $signer = $this->makeSentEnvelope([
            'auth_method' => 'email_otp',
            'expected_cpf' => '529.982.247-25',
        ]);

        $this->post("/sign/{$signer->token}", $this->signPayload([
            'cpf' => '111.444.777-35',
            'otp_code' => '000000',
        ]))->assertSessionHasErrors('otp_code');

        $this->assertSame(0, $signer->fresh()->cpfAttempts());
    }
}
