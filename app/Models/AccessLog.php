<?php

namespace App\Models;

use Database\Factories\AccessLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    /** @use HasFactory<AccessLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'event', 'ip_address', 'user_agent', 'meta',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function eventLabel(): string
    {
        return match ($this->event) {
            'login'         => 'Login',
            'logout'        => 'Logout',
            'access_denied' => 'Acesso negado',
            default         => $this->event,
        };
    }

    public function eventColor(): string
    {
        return match ($this->event) {
            'login'         => 'green',
            'logout'        => 'gray',
            'access_denied' => 'red',
            default         => 'blue',
        };
    }
    
}
