<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\ActiveSession;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        // Usuários
        $clientsTotal = User::where('role', 'client')->count();

        // Online agora (last_seen_at < 2 min)
        $onlineNow = ActiveSession::with('user')
            ->where('last_seen_at', '>=', now()->subMinutes(2))
            ->get();

        // Acessos negados hoje
        $deniedToday = AccessLog::where('event', 'access_denied')
            ->whereDate('created_at', today())
            ->count();

        // Últimos eventos de log
        $recentLogs = AccessLog::with('user')
            ->latest('created_at')
            ->limit(10)
            ->get();

        // Últimos usuários cadastrados
        $recentUsers = User::where('role', 'client')
            ->latest()
            ->limit(5)
            ->get();

        return view('admin.dashboard', compact(
            'clientsTotal', 'onlineNow', 'deniedToday', 'recentLogs', 'recentUsers'
        ));
    }
}
