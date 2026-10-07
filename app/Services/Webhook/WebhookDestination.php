<?php

namespace App\Services\Webhook;

/**
 * Decide se a plataforma pode fazer POST numa URL de webhook — e em qual IP.
 *
 * A URL é do cliente: sem esta conferência, um domínio público apontando para
 * 10.x/127.x/169.254.x faria o servidor sondar a própria rede. Por isso resolve o
 * DNS e exige que TODOS os IPs sejam públicos, e o envio trava a conexão no IP
 * conferido (CURLOPT_RESOLVE), para o DNS não trocar entre a conferência e o POST.
 * Roda no cadastro (regra WebhookUrl) e de novo a cada envio (WebhookSender).
 */
class WebhookDestination
{
    public function __construct(private WebhookHostResolver $resolver) {}

    /**
     * @return array{refusal: ?string, pin: ?string} refusal = motivo em pt-BR (null = pode enviar);
     *                                                 pin = "host:porta:ip" para CURLOPT_RESOLVE
     */
    public function inspect(string $url): array
    {
        // O integrador testa na própria máquina (http://localhost:8000/...).
        if (app()->environment('local')) {
            return ['refusal' => null, 'pin' => null];
        }

        $parts = parse_url($url) ?: [];
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            return $this->refuse('A URL precisa usar https.');
        }

        // IP digitado direto: o curl conecta nele mesmo, não há DNS para trocar.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? ['refusal' => null, 'pin' => null] : $this->refuseInternal();
        }

        if (! $this->looksLikePublicHostname($host)) {
            return $this->refuseInternal();
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            return $this->refuse('Não foi possível encontrar o endereço deste domínio.');
        }
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return $this->refuseInternal();
            }
        }

        $ip = $ips[0];
        $port = $parts['port'] ?? 443;

        return ['refusal' => null, 'pin' => "{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)];
    }

    private function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function looksLikePublicHostname(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // Sem domínio de topo alfabético é nome interno ("intranet") ou IP disfarçado
        // ("2130706433", "127.1"), que o curl aceita como 127.0.0.1.
        $labels = explode('.', $host);

        return count($labels) >= 2 && preg_match('/^[a-z][a-z0-9-]*$/', end($labels)) === 1;
    }

    private function refuseInternal(): array
    {
        return $this->refuse('A URL não pode apontar para um endereço local ou de rede interna.');
    }

    private function refuse(string $reason): array
    {
        return ['refusal' => $reason, 'pin' => null];
    }
}
