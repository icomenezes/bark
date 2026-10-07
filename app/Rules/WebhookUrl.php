<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL de webhook cadastrada pelo cliente. A plataforma faz POST nela, então fora do
 * ambiente local exige https e barra hosts que apontam para a própria máquina ou para
 * a rede interna. O nome do host não é resolvido aqui (ver spec da API, "Webhooks").
 */
class WebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) && strlen($value) <= 2048 && filter_var($value, FILTER_VALIDATE_URL)
            ? parse_url($value)
            : false;
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! $parts || ! in_array($scheme, ['http', 'https'], true) || $host === '') {
            $fail('Informe uma URL válida, começando com https://.');

            return;
        }

        // O integrador testa na própria máquina (http://localhost:8000/...).
        if (app()->environment('local')) {
            return;
        }

        if ($scheme !== 'https') {
            $fail('A URL precisa usar https.');

            return;
        }

        if ($this->isInternalHost($host)) {
            $fail('A URL não pode apontar para um endereço local ou de rede interna.');
        }
    }

    private function isInternalHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Sem domínio de topo alfabético é nome interno ("intranet") ou IP disfarçado
        // ("2130706433", "127.1"), que o curl aceita como 127.0.0.1.
        $labels = explode('.', $host);

        return count($labels) < 2 || ! preg_match('/^[a-z][a-z0-9-]*$/', end($labels));
    }
}
