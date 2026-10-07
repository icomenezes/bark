<?php

namespace Tests\Concerns;

use App\Services\Webhook\WebhookHostResolver;

/** DNS falso para os webhooks: os testes não dependem de rede nem de domínios reais. */
trait FakesWebhookDns
{
    /** @param array<string, list<string>> $records host => IPs; host ausente não resolve. */
    protected function fakeWebhookDns(array $records): void
    {
        $this->app->instance(WebhookHostResolver::class, new class($records) extends WebhookHostResolver
        {
            public function __construct(private array $records) {}

            public function resolve(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        });
    }
}
