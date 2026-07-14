<?php

namespace App\Http\Controllers;

use App\Services\AccessLogService;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function __invoke(Request $request, AccessLogService $log)
    {
        $log->heartbeat($request->user());
        return response()->json(['ok' => true]);
    }
}
