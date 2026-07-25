<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\DashboardStatsService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardStatsService $stats) {}

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return view('client.dashboard', ['stats' => $this->stats->client($user)]);
    }
}
