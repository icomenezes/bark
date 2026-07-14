<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

class NotificationService
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function sendWhatsApp(User $user, string $message): void
    {
        if (! $user->whatsapp) return;

        try {
            $settings = Setting::current();
            if (! $settings->whatsapp_enabled) return;
        } catch (\Throwable) {
            return;
        }

        $this->whatsapp->send($user->whatsapp, $message);
    }

    public function boasVindas(User $user): void
    {
        $this->sendWhatsApp($user,
            "👋 Olá, *{$user->name}*! Sua conta foi criada com sucesso.\n" .
            "Acesse: " . config('app.url')
        );
    }
}
