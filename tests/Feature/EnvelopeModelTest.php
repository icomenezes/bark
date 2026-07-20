<?php

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvelopeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_signer_gets_random_64_char_token_on_create(): void
    {
        $signer = EnvelopeSigner::factory()->create();

        $this->assertSame(64, strlen($signer->token));

        $other = EnvelopeSigner::factory()->create();
        $this->assertNotSame($signer->token, $other->token);
    }

    public function test_all_signed_and_next_pending_signer(): void
    {
        $envelope = Envelope::factory()->create(['signing_order' => 'sequential']);
        $first = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 1]);
        $second = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 2]);

        $this->assertFalse($envelope->allSigned());
        $this->assertTrue($envelope->nextPendingSigner()->is($first));

        $first->update(['status' => 'signed', 'signed_at' => now()]);
        $envelope->refresh();

        $this->assertFalse($envelope->allSigned());
        $this->assertTrue($envelope->nextPendingSigner()->is($second));

        $second->update(['status' => 'signed', 'signed_at' => now()]);
        $envelope->refresh();

        $this->assertTrue($envelope->allSigned());
        $this->assertNull($envelope->nextPendingSigner());
        $this->assertSame(['signed' => 2, 'total' => 2], $envelope->progress());
    }

    public function test_requires_otp_by_auth_method(): void
    {
        $this->assertFalse(EnvelopeSigner::factory()->create(['auth_method' => 'link'])->requiresOtp());
        $this->assertTrue(EnvelopeSigner::factory()->create(['auth_method' => 'email_otp'])->requiresOtp());
        $this->assertTrue(EnvelopeSigner::factory()->create(['auth_method' => 'whatsapp_otp'])->requiresOtp());
    }

    public function test_can_sign_only_when_envelope_sent_and_signer_pending(): void
    {
        $sent = Envelope::factory()->create(['status' => 'sent']);
        $signer = EnvelopeSigner::factory()->for($sent)->create(['status' => 'notified']);
        $this->assertTrue($signer->canSign());

        $signer->update(['status' => 'signed']);
        $this->assertFalse($signer->fresh()->canSign());

        $cancelled = Envelope::factory()->create(['status' => 'cancelled']);
        $s2 = EnvelopeSigner::factory()->for($cancelled)->create();
        $this->assertFalse($s2->canSign());

        $expired = Envelope::factory()->create(['status' => 'sent', 'expires_at' => now()->subDay()]);
        $s3 = EnvelopeSigner::factory()->for($expired)->create();
        $this->assertFalse($s3->canSign());
    }

    public function test_envelope_event_has_no_updated_at_and_casts_meta(): void
    {
        $envelope = Envelope::factory()->create();
        $event = $envelope->events()->create(['event' => 'created', 'meta' => ['a' => 1]]);

        $this->assertSame(['a' => 1], $event->fresh()->meta);
        $this->assertNull($event::UPDATED_AT);
    }

    public function test_signer_defaults_to_email_channel(): void
    {
        $signer = EnvelopeSigner::factory()->create();

        $this->assertSame('email', $signer->channel);
    }

    public function test_signer_channel_can_be_whatsapp(): void
    {
        $signer = EnvelopeSigner::factory()->create([
            'channel' => 'whatsapp',
            'whatsapp' => '11999998888',
            'auth_method' => 'whatsapp_otp',
        ]);

        $this->assertSame('whatsapp', $signer->fresh()->channel);
    }

    public function test_signer_defaults_to_send_signed_copy_true(): void
    {
        $signer = EnvelopeSigner::factory()->create();

        $this->assertTrue($signer->fresh()->send_signed_copy);
    }

    public function test_signer_send_signed_copy_can_be_set_false(): void
    {
        $signer = EnvelopeSigner::factory()->create(['send_signed_copy' => false]);

        $this->assertFalse($signer->fresh()->send_signed_copy);
    }
}
