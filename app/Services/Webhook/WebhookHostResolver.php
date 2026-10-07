<?php

namespace App\Services\Webhook;

/** Resolve o DNS do host de um webhook (IPv4 e IPv6). Isolado para os testes trocarem por um falso. */
class WebhookHostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
