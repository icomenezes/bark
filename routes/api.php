<?php

use App\Http\Controllers\Api\V1\EnvelopeApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('envelopes', [EnvelopeApiController::class, 'store']);
    Route::get('envelopes/{envelope}', [EnvelopeApiController::class, 'show']);
});
