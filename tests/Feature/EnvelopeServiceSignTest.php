<?php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Mail\Envelopes\EnvelopeCancelled;
use App\Mail\Envelopes\EnvelopeDeclined;
use App\Mail\Envelopes\EnvelopeOtp;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopeServiceSignTest extends TestCase
{
    use RefreshDatabase;

    private function pngDataUrl(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    private function signData(): array
    {
        return ['name' => 'Ana Silva', 'cpf' => '123.456.789-00',
                'signature_type' => 'drawn', 'signature' => $this->pngDataUrl()];
    }

    public function test_issue_and_verify_otp(): void
    {
        Mail::fake();
        $signer = EnvelopeSigner::factory()->create(['auth_method' => 'email_otp']);
        $svc = app(EnvelopeService::class);

        $svc->issueOtp($signer);
        Mail::assertSent(EnvelopeOtp::class, function (EnvelopeOtp $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->assertFalse($svc->verifyOtp($signer->fresh(), '000000'));
        $this->assertTrue($svc->verifyOtp($signer->fresh(), $code));
        $this->assertTrue($signer->envelope->events()->where('event', 'otp_failed')->exists());
    }

    public function test_otp_expires_and_locks_after_5_attempts(): void
    {
        Mail::fake();
        $signer = EnvelopeSigner::factory()->create(['auth_method' => 'email_otp']);
        $svc = app(EnvelopeService::class);

        $svc->issueOtp($signer);
        $signer->fresh()->update(['otp_expires_at' => now()->subMinute()]);
        $this->assertFalse($svc->verifyOtp($signer->fresh(), '123456'));

        $svc->issueOtp($signer = $signer->fresh());
        $signer->update(['otp_attempts' => 5]);
        $this->assertFalse($svc->verifyOtp($signer->fresh(), '123456'));
    }

    public function test_sign_stores_signature_and_dispatches_seal_when_last(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $a = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 1, 'status' => 'viewed']);
        $b = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 2, 'status' => 'viewed']);
        $svc = app(EnvelopeService::class);

        $svc->sign($a, $this->signData(), '10.0.0.1', 'UA-Test');
        Queue::assertNotPushed(SealEnvelopeJob::class);

        $a->refresh();
        $this->assertSame('signed', $a->status);
        $this->assertSame('Ana Silva', $a->name);
        $this->assertSame('123.456.789-00', $a->cpf);
        $this->assertSame('10.0.0.1', $a->ip_address);
        Storage::disk('local')->assertExists($a->signature_image_path);

        $svc->sign($b, $this->signData(), '10.0.0.2', 'UA-Test');
        Queue::assertPushed(SealEnvelopeJob::class, 1);
    }

    public function test_sequential_sign_notifies_next(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent', 'signing_order' => 'sequential']);
        $a = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 1, 'status' => 'viewed']);
        $b = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 2, 'status' => 'pending']);

        app(EnvelopeService::class)->sign($a, $this->signData(), null, null);

        $this->assertSame('notified', $b->fresh()->status);
    }

    public function test_decline_ends_envelope_and_notifies_sender(): void
    {
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $signer = EnvelopeSigner::factory()->for($envelope)->create(['status' => 'viewed']);

        app(EnvelopeService::class)->decline($signer, 'Valores errados', '10.0.0.1', 'UA');

        $this->assertSame('declined', $signer->fresh()->status);
        $this->assertSame('declined', $envelope->fresh()->status);
        Mail::assertSent(EnvelopeDeclined::class, fn ($m) => $m->hasTo($envelope->user->email));
    }

    public function test_cancel_notifies_already_notified_signers(): void
    {
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'notified', 'email' => 'a@x.com']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'pending', 'email' => 'b@x.com']);

        app(EnvelopeService::class)->cancel($envelope);

        $this->assertSame('cancelled', $envelope->fresh()->status);
        Mail::assertSent(EnvelopeCancelled::class, 1);
        Mail::assertSent(EnvelopeCancelled::class, fn ($m) => $m->hasTo('a@x.com'));
    }
}
