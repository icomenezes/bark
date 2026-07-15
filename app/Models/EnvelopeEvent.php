<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trilha de auditoria imutável — somente INSERT. */
class EnvelopeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'envelope_id', 'envelope_signer_id', 'event',
        'ip_address', 'user_agent', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(EnvelopeSigner::class, 'envelope_signer_id');
    }
}
