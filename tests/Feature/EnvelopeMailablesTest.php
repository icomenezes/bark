<?php

namespace Tests\Feature;

use App\Mail\Envelopes\EnvelopeCancelled;
use App\Mail\Envelopes\EnvelopeCompleted;
use App\Mail\Envelopes\EnvelopeDeclined;
use App\Mail\Envelopes\EnvelopeInvite;
use App\Mail\Envelopes\EnvelopeOtp;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvelopeMailablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_renders_with_sign_link_and_sender_message(): void
    {
        $envelope = Envelope::factory()->create(['title' => 'Contrato de Aluguel', 'message' => 'Favor assinar até sexta.']);
        $signer = EnvelopeSigner::factory()->for($envelope)->create();

        $html = (new EnvelopeInvite($signer))->render();

        $this->assertStringContainsString('Contrato de Aluguel', $html);
        $this->assertStringContainsString('Favor assinar até sexta.', $html);
        $this->assertStringContainsString(route('public.sign.show', $signer->token), $html);
    }

    public function test_reminder_changes_subject(): void
    {
        $signer = EnvelopeSigner::factory()->create();

        $this->assertStringContainsString('Lembrete', (new EnvelopeInvite($signer, reminder: true))->envelope()->subject);
    }

    public function test_otp_renders_code(): void
    {
        $signer = EnvelopeSigner::factory()->create();

        $this->assertStringContainsString('123456', (new EnvelopeOtp($signer, '123456'))->render());
    }

    public function test_completed_declined_cancelled_render(): void
    {
        $envelope = Envelope::factory()->create(['status' => 'completed']);
        $signer = EnvelopeSigner::factory()->for($envelope)->create(['decline_reason' => 'Valores incorretos']);

        $this->assertStringContainsString(route('public.sign.document', $signer->token), (new EnvelopeCompleted($envelope, $signer))->render());
        $this->assertStringContainsString(route('envelopes.download', $envelope), (new EnvelopeCompleted($envelope))->render());
        $this->assertStringContainsString('Valores incorretos', (new EnvelopeDeclined($envelope, $signer))->render());
        $this->assertStringContainsString($envelope->title, (new EnvelopeCancelled($envelope))->render());
    }
}
