<?php

namespace Tests\Feature\Webhook;

use App\Jobs\SendEnvelopeWebhookJob;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\User;
use App\Services\Envelope\EnvelopeService;
use App\Services\Webhook\EnvelopeWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnvelopeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function owner(?string $url = 'https://ponto.example.com/hook'): User
    {
        return User::factory()->create(['webhook_url' => $url]);
    }

    public function test_queues_the_webhook_for_api_envelopes_of_accounts_with_a_url(): void
    {
        $this->freezeTime();
        Queue::fake();
        $envelope = Envelope::factory()->for($this->owner())->create(['source' => 'api']);

        app(EnvelopeWebhook::class)->dispatch($envelope, 'envelope.cancelled');

        Queue::assertPushed(SendEnvelopeWebhookJob::class, function (SendEnvelopeWebhookJob $job) use ($envelope) {
            return $job->envelopeId === $envelope->id
                && $job->event === 'envelope.cancelled'
                && Str::isUuid($job->eventId)
                && $job->occurredAt === now()->toIso8601String();
        });
    }

    public function test_ignores_envelopes_created_on_the_web(): void
    {
        Queue::fake();
        $envelope = Envelope::factory()->for($this->owner())->create(['source' => 'web']);

        app(EnvelopeWebhook::class)->dispatch($envelope, 'envelope.cancelled');

        Queue::assertNothingPushed();
    }

    public function test_ignores_accounts_without_a_url(): void
    {
        Queue::fake();
        $envelope = Envelope::factory()->for($this->owner(url: null))->create(['source' => 'api']);

        app(EnvelopeWebhook::class)->dispatch($envelope, 'envelope.cancelled');

        Queue::assertNothingPushed();
    }

    public function test_cancelling_an_api_envelope_dispatches_the_cancelled_webhook(): void
    {
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->for($this->owner())->create(['source' => 'api', 'status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'notified']);

        app(EnvelopeService::class)->cancel($envelope);

        Queue::assertPushed(SendEnvelopeWebhookJob::class, fn ($job) => $job->envelopeId === $envelope->id
            && $job->event === 'envelope.cancelled');
    }
}
