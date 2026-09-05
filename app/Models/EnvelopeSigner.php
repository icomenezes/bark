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

    /** Divergências de CPF aceitas antes de travar o link do signatário. */
    public const MAX_CPF_ATTEMPTS = 5;

    protected $fillable = [
        'envelope_id', 'saved_signer_id', 'name', 'email', 'whatsapp', 'cpf', 'expected_cpf',
        'channel', 'send_signed_copy',
        'auth_method', 'sign_position', 'token', 'status',
        'signature_image_path', 'signature_type',
        'consent_accepted_at', 'consent_version',
        'otp_code', 'otp_expires_at', 'otp_attempts',
        'signed_at', 'ip_address', 'user_agent', 'decline_reason',
    ];

    protected $hidden = ['otp_code', 'token'];

    protected function casts(): array
    {
        return [
            'otp_expires_at' => 'datetime',
            'signed_at' => 'datetime',
            'consent_accepted_at' => 'datetime',
            'send_signed_copy' => 'boolean',
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

    public function savedSigner(): BelongsTo
    {
        return $this->belongsTo(SavedSigner::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(EnvelopeField::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EnvelopeEvent::class, 'envelope_signer_id');
    }

    public function requiresOtp(): bool
    {
        return $this->auth_method !== 'link';
    }

    /**
     * Divergências de CPF desde o último desbloqueio.
     *
     * Conta por id — os eventos são insert-only e auto-incrementais, enquanto
     * created_at tem granularidade de segundo e empataria em tentativas rápidas.
     */
    public function cpfAttempts(): int
    {
        $lastUnlock = $this->events()->where('event', 'cpf_unlocked')->max('id');

        return $this->events()
            ->where('event', 'cpf_mismatch')
            ->when($lastUnlock, fn ($query) => $query->where('id', '>', $lastUnlock))
            ->count();
    }

    public function isCpfLocked(): bool
    {
        return $this->expected_cpf !== null && $this->cpfAttempts() >= self::MAX_CPF_ATTEMPTS;
    }

    /** Pode assinar agora: envelope enviado, não expirado, e este signatário ainda pendente. */
    public function canSign(): bool
    {
        return in_array($this->status, ['pending', 'notified', 'viewed'], true)
            && $this->envelope->status === 'sent'
            && ! $this->envelope->isExpired();
    }
}
