<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActiveSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'ip_address', 'user_agent', 'logged_in_at', 'last_seen_at',
    ];

    protected $casts = [
        'logged_in_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isOnline(): bool
    {
        // considera online se visto nos últimos 2 minutos
        return $this->last_seen_at->diffInSeconds(now()) < 120;
    }
}
