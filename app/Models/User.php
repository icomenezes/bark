<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'whatsapp'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
}
