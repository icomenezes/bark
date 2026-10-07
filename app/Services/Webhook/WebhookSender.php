<?php

namespace App\Services\Webhook;

use App\Models\Envelope;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uma tentativa de entrega: confere o destino, assina, faz o POST na URL atual da
 * conta e registra o resultado. Não decide sobre novas tentativas — isso é do
 * SendEnvelopeWebhookJob.
 */
class WebhookSender
{
    public const TIMEOUT_SECONDS = 10;

    public function __construct(private WebhookDestination $destination) {}

    public function send(User $user, string $eventId, string $event, array $body, ?Envelope $envelope, int $attempt): WebhookDelivery
    {
        $status = null;
        $error = null;

        // De novo a cada envio: o DNS pode ter mudado desde o cadastro.
        $destination = $this->destination->inspect((string) $user->webhook_url);

        if ($destination['refusal'] !== null) {
            $error = 'Destino recusado: '.$destination['refusal'];
        } else {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $timestamp = (string) time();

            $request = Http::timeout(self::TIMEOUT_SECONDS)->withoutRedirecting();
            if ($destination['pin'] !== null) {
                $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$destination['pin']]]]);
            }

            try {
                $response = $request
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
                // Para a tela, só a categoria; o detalhe do curl fica no log para quem opera o servidor.
                $error = str_contains($e->getMessage(), 'cURL error 28') ? 'Tempo esgotado' : 'Falha de conexão';
                Log::info('Webhook: falha de conexão', ['user_id' => $user->id, 'event_id' => $eventId, 'error' => $e->getMessage()]);
            }
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
