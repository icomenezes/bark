<?php

use App\Http\Controllers\Admin\AccessLogController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Client\CertificateController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\EnvelopeController;
use App\Http\Controllers\Client\SignDocumentController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicRegisterController;
use App\Http\Controllers\PublicSign\SignEnvelopeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// Admin routes
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    // Usuários
    Route::resource('users', UserController::class)->only(['index', 'show', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::post('users/{user}/api-token', [UserController::class, 'generateApiToken'])->name('users.api-token.generate');
    Route::delete('users/{user}/api-token', [UserController::class, 'revokeApiToken'])->name('users.api-token.revoke');

    // Planos (limites de uso mensal)
    Route::resource('plans', PlanController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);

    // Logs de acesso
    Route::get('access-logs', [AccessLogController::class, 'index'])->name('access-logs.index');

    // Configurações (white-label)
    Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::patch('settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('settings/whatsapp-test', [SettingController::class, 'testWhatsApp'])->name('settings.whatsapp-test');
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

// Envelopes (assinatura eletrônica multi-signatário)
Route::middleware('auth')->group(function () {
    Route::get('envelopes', [EnvelopeController::class, 'index'])->name('envelopes.index');
    Route::get('envelopes/create', [EnvelopeController::class, 'create'])->name('envelopes.create');
    Route::post('envelopes', [EnvelopeController::class, 'store'])->name('envelopes.store');
    Route::get('envelopes/{envelope}', [EnvelopeController::class, 'show'])->name('envelopes.show');
    Route::post('envelopes/{envelope}/remind', [EnvelopeController::class, 'remind'])->name('envelopes.remind');
    Route::post('envelopes/{envelope}/cancel', [EnvelopeController::class, 'cancel'])->name('envelopes.cancel');
    Route::post('envelopes/{envelope}/reseal', [EnvelopeController::class, 'reseal'])->name('envelopes.reseal');
    Route::get('envelopes/{envelope}/download', [EnvelopeController::class, 'download'])->name('envelopes.download');
});
// Assinatura pública de envelopes — autorização é o próprio token
Route::prefix('sign/{token}')->name('public.sign.')->group(function () {
    Route::get('/', [SignEnvelopeController::class, 'show'])->middleware('throttle:30,1')->name('show');
    Route::get('document', [SignEnvelopeController::class, 'document'])->middleware('throttle:30,1')->name('document');
    Route::post('otp', [SignEnvelopeController::class, 'otp'])->middleware('throttle:5,1')->name('otp');
    Route::post('/', [SignEnvelopeController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::post('decline', [SignEnvelopeController::class, 'decline'])->middleware('throttle:10,1')->name('decline');
});

require __DIR__.'/auth.php';
