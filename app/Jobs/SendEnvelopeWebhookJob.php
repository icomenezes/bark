<?php

namespace App\Jobs;

use App\Models\Envelope;
use App\Services\Webhook\WebhookSender;
use App\Support\EnvelopeApiPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Entrega o webhook de um envelope (envelope.signed / envelope.cancelled).
 *
 * Falha do receptor não é erro da plataforma: em vez de lançar exceção (que iria
 * para o log a cada tentativa), devolve o job à fila com o próximo intervalo.
 */
class SendEnvelopeWebhookJob implements ShouldQueue
{
    use Queueable;

    /** Intervalo antes da tentativa seguinte, em segundos: 1 min, 5 min, 15 min, 1 h, 3 h. */
    private const BACKOFF = [60, 300, 900, 3600, 10800];

    public int $tries = 6;

    public function __construct(
        public int $envelopeId,
        public string $event,
        public string $eventId,
        public string $occurredAt,
    ) {}

    public function handle(WebhookSender $sender): void
    {
        $envelope = Envelope::with('user')->find($this->envelopeId);

        // URL e segredo são lidos a cada tentativa: corrigir a URL redireciona as próximas, removê-la encerra.
        if ($envelope === null || ! $envelope->user->webhook_url) {
            return;
        }

        $delivery = $sender->send($envelope->user, $this->eventId, $this->event, [
            'id' => $this->eventId,
            'event' => $this->event,
            'occurred_at' => $this->occurredAt,
            'envelope' => EnvelopeApiPayload::of($envelope),
        ], $envelope, $this->attempts());

        if (! $delivery->successful() && $this->attempts() < $this->tries) {
            $this->release(self::BACKOFF[$this->attempts() - 1]);
        }
    }
}
