<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\ActiveSession;
use App\Models\User;
use Illuminate\Http\Request;

class AccessLogService
{
    public function __construct(private Request $request) {}

    public function log(User $user, string $event, array $meta = []): void
    {
        AccessLog::create([
            'user_id'    => $user->id,
            'event'      => $event,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'meta'       => $meta ?: null,
        ]);
    }

    public function login(User $user): void
    {
        $this->log($user, 'login');
        $this->upsertSession($user);
    }

    public function logout(User $user): void
    {
        $this->log($user, 'logout');
        ActiveSession::where('user_id', $user->id)->delete();
    }

    public function denied(User $user, string $reason = ''): void
    {
        $this->log($user, 'access_denied', ['reason' => $reason]);
    }

    public function heartbeat(User $user): void
    {
        ActiveSession::where('user_id', $user->id)->update([
            'last_seen_at' => now(),
        ]);
    }

    private function upsertSession(User $user): void
    {
        ActiveSession::updateOrCreate(
            ['user_id' => $user->id],
            [
                'ip_address'   => $this->request->ip(),
                'user_agent'   => $this->request->userAgent(),
                'logged_in_at' => now(),
                'last_seen_at' => now(),
            ]
        );
    }
}
