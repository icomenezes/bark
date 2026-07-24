<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Envelope extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'message',
        'verification_code',
        'original_pdf_path', 'final_pdf_path',
        'sha256_original', 'sha256_final',
        'signing_order', 'status', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signers(): HasMany
    {
        return $this->hasMany(EnvelopeSigner::class)->orderBy('sign_position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EnvelopeEvent::class)->orderBy('id');
    }

    public function isSequential(): bool
    {
        return $this->signing_order === 'sequential';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function allSigned(): bool
    {
        return ! $this->signers()->where('status', '!=', 'signed')->exists();
    }

    /** Próximo que ainda não assinou/recusou, em ordem de posição. */
    public function nextPendingSigner(): ?EnvelopeSigner
    {
        return $this->signers()->whereNotIn('status', ['signed', 'declined'])->first();
    }

    /** @return array{signed:int,total:int} */
    public function progress(): array
    {
        return [
            'signed' => $this->signers()->where('status', 'signed')->count(),
            'total' => $this->signers()->count(),
        ];
    }
}
