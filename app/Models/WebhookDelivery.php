<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma tentativa de envio de webhook. Apagada após 90 dias (model:prune). */
class WebhookDelivery extends Model
{
    use Prunable;

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'envelope_id', 'event_id', 'event', 'url', 'attempt', 'response_status', 'error',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function successful(): bool
    {
        return $this->response_status !== null && $this->response_status >= 200 && $this->response_status < 300;
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(90));
    }
}
