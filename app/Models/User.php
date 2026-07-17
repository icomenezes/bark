<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'whatsapp', 'plan_id',
        'signing_certificate_id', 'whatsapp_envelope_enabled', 'default_envelope_channel',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'whatsapp_envelope_enabled' => 'boolean',
        ];
    }

    // ── Roles ────────────────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    // ── Sessão ativa ─────────────────────────────────────────────────────────

    public function activeSession()
    {
        return $this->hasOne(ActiveSession::class);
    }

    public function isOnline(): bool
    {
        $session = $this->activeSession;

        return $session !== null && $session->isOnline();
    }

    // ── Logs de acesso ───────────────────────────────────────────────────────

    public function accessLogs()
    {
        return $this->hasMany(AccessLog::class)->latest('created_at');
    }

    // ── Certificados digitais ────────────────────────────────────────────────

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    public function signingCertificate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'signing_certificate_id');
    }

    // ── Plano de uso ─────────────────────────────────────────────────────────

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    // ── Redefinição de senha ─────────────────────────────────────────────────

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
