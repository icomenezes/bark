<?php

use App\Http\Controllers\Admin\AccessLogController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicRegisterController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// Admin routes
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    // Usuários
    Route::resource('users', UserController::class)->only(['index', 'show', 'create', 'store', 'destroy']);

    // Logs de acesso
    Route::get('access-logs', [AccessLogController::class, 'index'])->name('access-logs.index');

    // Configurações (white-label)
    Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::patch('settings', [SettingController::class, 'update'])->name('settings.update');
});

// Heartbeat — cliente envia a cada 30s para manter sessão ativa
Route::middleware('auth')->post('/heartbeat', HeartbeatController::class)->name('heartbeat');

// Client routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

// Cadastro público — recebe leads de site externo
Route::post('/api/register', [PublicRegisterController::class, 'store'])
    ->name('api.register');

// Profile
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
