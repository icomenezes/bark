<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvelopeField extends Model
{
    protected $fillable = ['envelope_signer_id', 'page', 'x', 'y', 'w', 'h'];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'w' => 'float',
            'h' => 'float',
        ];
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(EnvelopeSigner::class, 'envelope_signer_id');
    }
}
