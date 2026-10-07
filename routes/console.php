<?php

use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('envelopes:expire')->hourly();

// Tentativas de webhook com mais de 90 dias (WebhookDelivery::prunable).
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->daily();
