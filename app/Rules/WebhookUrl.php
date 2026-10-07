<?php

namespace App\Rules;

use App\Services\Webhook\WebhookDestination;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL de webhook cadastrada pelo cliente: formato aqui; destino (https, DNS, rede
 * interna) na WebhookDestination, que o envio repete a cada tentativa.
 */
class WebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) && strlen($value) <= 2048 && filter_var($value, FILTER_VALIDATE_URL)
            ? parse_url($value)
            : false;

        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            $fail('Informe uma URL válida, começando com https://.');

            return;
        }

        $refusal = app(WebhookDestination::class)->inspect($value)['refusal'];
        if ($refusal !== null) {
            $fail($refusal);
        }
    }
}
