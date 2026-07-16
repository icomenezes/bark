<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $baseUrl;
    private string $instance;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.evolution.url', '') ?? '', '/');
        $this->instance = config('services.evolution.instance', '') ?? '';
        $this->apiKey   = config('services.evolution.key', '') ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl && $this->instance && $this->apiKey;
    }

    public function send(string $phone, string $message): bool
    {
        return $this->sendWithDetails($phone, $message)['ok'];
    }

    /** @return array{ok: bool, error: ?string} */
    public function sendWithDetails(string $phone, string $message): array
    {
        if (! $this->isConfigured()) {
            Log::warning('WhatsApp: Evolution API não configurada.');
            return ['ok' => false, 'error' => 'Evolution API não configurada (URL, instância ou chave ausentes).'];
        }

        $number = $this->normalizeNumber($phone);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['apikey' => $this->apiKey])
                ->post("{$this->baseUrl}/message/sendText/{$this->instance}", [
                    'number'  => $number,
                    'text'    => $message,
                    'delay'   => 0,
                ]);

            if ($response->successful()) {
                return ['ok' => true, 'error' => null];
            }

            Log::error('WhatsApp falhou', ['status' => $response->status(), 'body' => $response->body()]);
            return ['ok' => false, 'error' => "HTTP {$response->status()}: {$response->body()}"];
        } catch (\Throwable $e) {
            Log::error('WhatsApp exception: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Normaliza para o formato aceito pela Evolution API: DDI 55 + DDD + número. */
    private function normalizeNumber(string $phone): string
    {
        $number = preg_replace('/\D/', '', $phone);

        // Celular sem DDI (11 dígitos: DDD + 9 + 8 dígitos) ou fixo sem DDI (10 dígitos: DDD + 8 dígitos)
        if (strlen($number) === 11 || strlen($number) === 10) {
            $number = '55' . $number;
        }

        return $number;
    }
}
