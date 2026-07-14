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
        if (! $this->isConfigured()) {
            Log::warning('WhatsApp: Evolution API não configurada.');
            return false;
        }

        $number = preg_replace('/\D/', '', $phone);
        if (strlen($number) === 11) {
            $number = '55' . $number; // adiciona DDI Brasil
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['apikey' => $this->apiKey])
                ->post("{$this->baseUrl}/message/sendText/{$this->instance}", [
                    'number'  => $number,
                    'text'    => $message,
                    'delay'   => 0,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('WhatsApp falhou', ['status' => $response->status(), 'body' => $response->body()]);
            return false;
        } catch (\Throwable $e) {
            Log::error('WhatsApp exception: ' . $e->getMessage());
            return false;
        }
    }
}
