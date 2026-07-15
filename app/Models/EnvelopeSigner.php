<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EnvelopeSigner extends Model
{
    use HasFactory;

    protected $fillable = [
        'envelope_id', 'name', 'email', 'whatsapp', 'cpf',
        'auth_method', 'sign_position', 'token', 'status',
        'signature_image_path', 'signature_type',
        'otp_code', 'otp_expires_at', 'otp_attempts',
        'signed_at', 'ip_address', 'user_agent', 'decline_reason',
    ];

    protected $hidden = ['otp_code', 'token'];

    protected function casts(): array
    {
        return [
            'otp_expires_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $signer) {
            $signer->token = $signer->token ?: Str::random(64);
        });
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(EnvelopeField::class);
    }

    public function requiresOtp(): bool
    {
        return $this->auth_method !== 'link';
    }

    /** Pode assinar agora: envelope enviado, não expirado, e este signatário ainda pendente. */
    public function canSign(): bool
    {
        return in_array($this->status, ['pending', 'notified', 'viewed'], true)
            && $this->envelope->status === 'sent'
            && ! $this->envelope->isExpired();
    }
}
