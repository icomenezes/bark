<?php

use App\Http\Controllers\Admin\AccessLogController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Client\CertificateController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\SignDocumentController;
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

    // Certificados digitais
    Route::resource('certificates', CertificateController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('certificates/{certificate}/image/{type}', [CertificateController::class, 'image'])
        ->whereIn('type', ['sign', 'logo'])
        ->name('certificates.image');

    // Assinatura de documentos
    Route::get('sign-document', [SignDocumentController::class, 'index'])->name('sign-document.index');
    Route::post('sign-document/sign', [SignDocumentController::class, 'sign'])->name('sign-document.sign');
    Route::post('sign-document/generate', [SignDocumentController::class, 'generate'])->name('sign-document.generate');
    Route::post('sign-document/preview-text', [SignDocumentController::class, 'previewText'])->name('sign-document.preview-text');
    Route::get('sign-document/download/{filename}', [SignDocumentController::class, 'download'])->name('sign-document.download');
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

// Envelopes — stubs; controllers entram nas Tasks 10 e 12
Route::middleware('auth')->group(function () {
    Route::get('envelopes/{envelope}/download', fn () => abort(501))->name('envelopes.download');
});
Route::get('/sign/{token}', fn () => abort(501))->name('public.sign.show');
Route::get('/sign/{token}/document', fn () => abort(501))->name('public.sign.document');

require __DIR__.'/auth.php';
