<?php

namespace App\Services\Webhook;

use App\Models\Envelope;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Uma tentativa de entrega: assina, faz o POST na URL atual da conta e registra o
 * resultado. Não decide sobre novas tentativas — isso é do SendEnvelopeWebhookJob.
 */
class WebhookSender
{
    public const TIMEOUT_SECONDS = 10;

    public function send(User $user, string $eventId, string $event, array $body, ?Envelope $envelope, int $attempt): WebhookDelivery
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $status = null;
        $error = null;

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders([
                    'X-Webhook-Event' => $event,
                    'X-Webhook-Id' => $eventId,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$json, (string) $user->webhook_secret),
                ])
                ->withBody($json, 'application/json')
                ->post($user->webhook_url);

            // O corpo da resposta nunca é lido: a URL é do cliente e pode apontar para qualquer lugar.
            $status = $response->status();
        } catch (ConnectionException $e) {
            $error = Str::limit($e->getMessage(), 250);
        }

        return WebhookDelivery::create([
            'user_id' => $user->id,
            'envelope_id' => $envelope?->id,
            'event_id' => $eventId,
            'event' => $event,
            'url' => $user->webhook_url,
            'attempt' => $attempt,
            'response_status' => $status,
            'error' => $error,
        ]);
    }
}
