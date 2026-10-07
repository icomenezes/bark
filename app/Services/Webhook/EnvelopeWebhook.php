<?php

namespace App\Services\Webhook;

use App\Jobs\SendEnvelopeWebhookJob;
use App\Models\Envelope;
use Illuminate\Support\Str;

/**
 * Ponto único de disparo dos webhooks de envelope (envelope.signed / envelope.cancelled).
 * Só envelopes criados pela API, e só se o dono cadastrou URL em /integration.
 */
class EnvelopeWebhook
{
    public function dispatch(Envelope $envelope, string $event): void
    {
        if ($envelope->source !== 'api' || ! $envelope->user->webhook_url) {
            return;
        }

        // id e occurred_at fixados aqui, não no job: precisam ser iguais em todas as tentativas.
        SendEnvelopeWebhookJob::dispatch($envelope->id, $event, (string) Str::uuid(), now()->toIso8601String());
    }
}
