<?php

namespace Tests\Feature;

use App\Mail\Envelopes\EnvelopeCancelled;
use App\Mail\Envelopes\EnvelopeCompleted;
use App\Mail\Envelopes\EnvelopeInvite;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use App\Services\Envelope\EnvelopeService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Signatário do canal e-mail com WhatsApp cadastrado recebe também uma cópia pelo WhatsApp. */
class EnvelopeWhatsAppCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake();
        config(['services.evolution.url' => 'https://evo.test', 'services.evolution.instance' => 'i', 'services.evolution.key' => 'k']);
        Setting::current()->update(['whatsapp_enabled' => true]);
        Setting::clearCache();
    }

    private function signer(array $attributes = [], bool $accountEnabled = true, string $envelopeStatus = 'sent'): EnvelopeSigner
    {
        $owner = User::factory()->create(['whatsapp_envelope_enabled' => $accountEnabled]);
        $envelope = Envelope::factory()->for($owner)->create(['status' => $envelopeStatus, 'title' => 'Folha de ponto 09/2026']);

        return EnvelopeSigner::factory()->for($envelope)->create(array_merge([
            'channel' => 'email', 'email' => 'func@example.com', 'whatsapp' => '11999998888',
        ], $attributes));
    }

    private function assertWhatsAppSentTo(string $number, string $textFragment): void
    {
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/message/sendText/')
            && str_contains($request['number'], $number)
            && str_contains($request['text'], $textFragment));
    }

    // ─── Conta habilitada + número: e-mail E WhatsApp ────────────────────────

    public function test_invite_goes_by_email_and_by_whatsapp(): void
    {
        $signer = $this->signer();

        app(EnvelopeService::class)->notifySigner($signer);

        Mail::assertSent(EnvelopeInvite::class, fn ($m) => $m->hasTo('func@example.com') && ! $m->reminder);
        $this->assertWhatsAppSentTo('11999998888', route('public.sign.show', $signer->token));
    }

    public function test_reminder_goes_by_email_and_by_whatsapp(): void
    {
        $signer = $this->signer(['status' => 'notified']);

        app(EnvelopeService::class)->notifySigner($signer, reminder: true);

        Mail::assertSent(EnvelopeInvite::class, fn ($m) => $m->hasTo('func@example.com') && $m->reminder);
        $this->assertWhatsAppSentTo('11999998888', route('public.sign.show', $signer->token));
    }

    public function test_completion_goes_by_email_and_by_whatsapp_with_the_document(): void
    {
        $signer = $this->signer(['status' => 'signed'], envelopeStatus: 'completed');

        app(EnvelopeService::class)->notifyCompletion($signer->envelope->fresh('signers'));

        Mail::assertSent(EnvelopeCompleted::class, fn ($m) => $m->hasTo('func@example.com'));
        $this->assertWhatsAppSentTo('11999998888', route('public.sign.document', $signer->token));
    }

    public function test_completion_respects_send_signed_copy_on_both_channels(): void
    {
        $signer = $this->signer(['status' => 'signed', 'send_signed_copy' => false], envelopeStatus: 'completed');

        app(EnvelopeService::class)->notifyCompletion($signer->envelope->fresh('signers'));

        Mail::assertNotSent(EnvelopeCompleted::class, fn ($m) => $m->hasTo('func@example.com'));
        Http::assertNothingSent();
    }

    public function test_cancellation_goes_by_email_and_by_whatsapp(): void
    {
        $signer = $this->signer(['status' => 'notified']);

        app(EnvelopeService::class)->cancel($signer->envelope);

        Mail::assertSent(EnvelopeCancelled::class, fn ($m) => $m->hasTo('func@example.com'));
        $this->assertWhatsAppSentTo('11999998888', 'cancelado');
    }

    // ─── Sem cópia ───────────────────────────────────────────────────────────

    public function test_account_without_whatsapp_enabled_gets_only_email(): void
    {
        $signer = $this->signer(accountEnabled: false);

        app(EnvelopeService::class)->notifySigner($signer);

        Mail::assertSent(EnvelopeInvite::class);
        Http::assertNothingSent();
    }

    public function test_signer_without_number_gets_only_email(): void
    {
        $signer = $this->signer(['whatsapp' => null]);

        app(EnvelopeService::class)->notifySigner($signer);

        Mail::assertSent(EnvelopeInvite::class);
        Http::assertNothingSent();
    }

    // ─── Falha na cópia ──────────────────────────────────────────────────────

    public function test_whatsapp_copy_failure_does_not_stop_the_email_or_the_flow(): void
    {
        Log::spy();
        $this->mock(NotificationService::class, fn ($mock) => $mock
            ->shouldReceive('sendWhatsAppTo')->andThrow(new \RuntimeException('Evolution fora do ar')));
        $signer = $this->signer();

        app(EnvelopeService::class)->notifySigner($signer);

        Mail::assertSent(EnvelopeInvite::class, fn ($m) => $m->hasTo('func@example.com'));
        $this->assertSame('notified', $signer->fresh()->status);
        $this->assertTrue($signer->envelope->events()->where('event', 'sent')->exists());
        Log::shouldHaveReceived('warning')->once();
    }

    // ─── Canal WhatsApp inalterado ───────────────────────────────────────────

    public function test_whatsapp_channel_still_gets_only_whatsapp(): void
    {
        $signer = $this->signer(['channel' => 'whatsapp', 'email' => null]);

        app(EnvelopeService::class)->notifySigner($signer);

        Mail::assertNothingSent();
        $this->assertWhatsAppSentTo('11999998888', route('public.sign.show', $signer->token));
    }

    public function test_otp_still_follows_only_the_channel(): void
    {
        $signer = $this->signer(['auth_method' => 'email_otp']);

        app(EnvelopeService::class)->issueOtp($signer);

        Http::assertNothingSent();
    }
}
