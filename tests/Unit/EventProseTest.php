<?php

namespace Tests\Unit;

use App\Models\EnvelopeEvent;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EventProse;
use Tests\TestCase;

class EventProseTest extends TestCase
{
    private function event(string $name, array $meta = [], ?string $ip = null): EnvelopeEvent
    {
        $event = new EnvelopeEvent(['event' => $name, 'meta' => $meta, 'ip_address' => $ip]);
        $event->setRelation('signer', new EnvelopeSigner(['name' => 'Ana Prova', 'email' => 'ana@exemplo.com']));

        return $event;
    }

    public function test_created_names_the_verification_code(): void
    {
        $prose = EventProse::for($this->event('created'), 'ABC-123');

        $this->assertStringContainsString('ABC-123', $prose);
        $this->assertStringContainsString('criado', $prose);
    }

    public function test_signed_keeps_the_existing_wording(): void
    {
        $prose = EventProse::for($this->event('signed', [
            'auth_method' => 'email_otp',
            'signature_type' => 'drawn',
        ], '10.0.0.9'), 'ABC-123');

        $this->assertStringContainsString('ANA PROVA', $prose);
        $this->assertStringContainsString('Assinou', $prose);
        $this->assertStringContainsString('código por e-mail', $prose);
        $this->assertStringContainsString('10.0.0.9', $prose);
    }

    public function test_consent_accepted_names_the_term_version(): void
    {
        $prose = EventProse::for($this->event('consent_accepted', ['version' => 'v1'], '10.0.0.9'), 'ABC-123');

        $this->assertStringContainsString('ANA PROVA', $prose);
        $this->assertStringContainsString('termo de assinatura eletrônica', $prose);
        $this->assertStringContainsString('v1', $prose);
        $this->assertStringContainsString('10.0.0.9', $prose);
    }

    public function test_cpf_mismatch_shows_only_the_masked_attempt(): void
    {
        $prose = EventProse::for($this->event('cpf_mismatch', ['attempted' => '***.444.777-**'], '10.0.0.9'), 'ABC-123');

        $this->assertStringContainsString('CPF divergente', $prose);
        $this->assertStringContainsString('***.444.777-**', $prose);
    }

    public function test_cpf_locked_and_unlocked_have_prose(): void
    {
        $locked = EventProse::for($this->event('cpf_locked'), 'ABC-123');
        $unlocked = EventProse::for($this->event('cpf_unlocked'), 'ABC-123');

        $this->assertStringContainsString('bloqueado', $locked);
        $this->assertStringContainsString('desbloqueado', $unlocked);
    }

    public function test_unknown_event_falls_back_to_its_name(): void
    {
        $this->assertSame('algo_novo', EventProse::for($this->event('algo_novo'), 'ABC-123'));
    }

    public function test_signer_supplied_text_is_escaped(): void
    {
        $event = new EnvelopeEvent(['event' => 'declined', 'meta' => ['reason' => '<script>x</script>']]);
        $event->setRelation('signer', new EnvelopeSigner(['name' => 'Ana']));

        $this->assertStringNotContainsString('<script>', EventProse::for($event, 'ABC-123'));
    }
}
