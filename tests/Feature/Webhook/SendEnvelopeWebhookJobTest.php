<?php

namespace Tests\Feature\Webhook;

use App\Jobs\SendEnvelopeWebhookJob;
use App\Models\Envelope;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Support\EnvelopeApiPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SendEnvelopeWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://ponto.example.com/webhooks/assinador';

    private function apiEnvelope(array $attributes = []): Envelope
    {
        $user = User::factory()->create(['webhook_url' => self::URL]);
        $user->forceFill(['webhook_secret' => 'whsec_segredo'])->save();

        return Envelope::factory()->for($user)->create(array_merge(['source' => 'api', 'status' => 'cancelled'], $attributes));
    }

    private function job(Envelope $envelope, int $attempt = 1, string $event = 'envelope.cancelled'): SendEnvelopeWebhookJob
    {
        $job = (new SendEnvelopeWebhookJob($envelope->id, $event, 'evt-1', '2026-10-07T14:32:10-03:00'))
            ->withFakeQueueInteractions();
        $job->job->attempts = $attempt;

        return $job;
    }

    public function test_delivers_the_envelope_as_the_get_endpoint_sees_it(): void
    {
        Storage::fake('documents');
        Http::fake([self::URL => Http::response('', 200)]);
        $envelope = $this->apiEnvelope(['status' => 'completed', 'completed_at' => now(), 'final_pdf_path' => 'users/1/envelopes/1/final.pdf']);
        Storage::disk('documents')->put('users/1/envelopes/1/final.pdf', '%PDF-1.4 final');

        $job = $this->job($envelope, event: 'envelope.signed');
        $job->handle(app(\App\Services\Webhook\WebhookSender::class));

        $job->assertNotReleased();
        Http::assertSent(function (Request $request) use ($envelope) {
            $body = json_decode($request->body(), true);
            $expected = EnvelopeApiPayload::of($envelope->fresh());

            return $body['id'] === 'evt-1'
                && $body['event'] === 'envelope.signed'
                && $body['occurred_at'] === '2026-10-07T14:32:10-03:00'
                && $body['envelope']['status'] === 'signed'
                && $body['envelope']['download_url'] !== null
                && array_keys($body['envelope']) === array_keys($expected)
                && $request->header('X-Webhook-Event')[0] === 'envelope.signed';
        });
        $this->assertSame(1, WebhookDelivery::where('envelope_id', $envelope->id)->where('attempt', 1)->count());
    }

    public function test_failure_is_retried_with_growing_intervals(): void
    {
        Http::fake([self::URL => Http::response('', 503)]);
        $envelope = $this->apiEnvelope();

        foreach ([1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 10800] as $attempt => $delay) {
            $job = $this->job($envelope, $attempt);
            $job->handle(app(\App\Services\Webhook\WebhookSender::class));
            $job->assertReleased(delay: $delay);
        }

        $this->assertSame(5, WebhookDelivery::where('event_id', 'evt-1')->count());
    }

    public function test_gives_up_after_the_sixth_attempt(): void
    {
        Http::fake([self::URL => Http::response('', 503)]);
        $envelope = $this->apiEnvelope();

        $job = $this->job($envelope, attempt: 6);
        $job->handle(app(\App\Services\Webhook\WebhookSender::class));

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(6, WebhookDelivery::where('event_id', 'evt-1')->value('attempt'));
    }

    public function test_uses_the_current_url_of_the_account(): void
    {
        Http::fake();
        $envelope = $this->apiEnvelope();
        $envelope->user->update(['webhook_url' => 'https://novo.example.com/hook']);

        $this->job($envelope)->handle(app(\App\Services\Webhook\WebhookSender::class));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://novo.example.com/hook');
    }

    public function test_stops_when_the_account_removed_its_url(): void
    {
        Http::fake();
        $envelope = $this->apiEnvelope();
        $envelope->user->update(['webhook_url' => null]);

        $job = $this->job($envelope, attempt: 2);
        $job->handle(app(\App\Services\Webhook\WebhookSender::class));

        Http::assertNothingSent();
        $job->assertNotReleased();
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_stops_when_the_envelope_no_longer_exists(): void
    {
        Http::fake();
        $envelope = $this->apiEnvelope();
        $job = $this->job($envelope);
        $envelope->delete();

        $job->handle(app(\App\Services\Webhook\WebhookSender::class));

        Http::assertNothingSent();
        $job->assertNotReleased();
    }
}
