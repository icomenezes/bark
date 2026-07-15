<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

class NotificationService
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function sendWhatsApp(User $user, string $message): void
    {
        $this->sendWhatsAppTo($user->whatsapp, $message);
    }

    /** Envia para número avulso (ex.: signatário de envelope, que não é user). */
    public function sendWhatsAppTo(?string $number, string $message): void
    {
        if (! $number) return;

        try {
            $settings = Setting::current();
            if (! $settings->whatsapp_enabled) return;
        } catch (\Throwable) {
            return;
        }

        $this->whatsapp->send($number, $message);
    }

    public function boasVindas(User $user): void
    {
        $this->sendWhatsApp($user,
            "👋 Olá, *{$user->name}*! Sua conta foi criada com sucesso.\n" .
            "Acesse: " . config('app.url')
        );
    }
}
