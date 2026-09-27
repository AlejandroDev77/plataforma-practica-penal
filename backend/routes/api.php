<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\ExpedienteArchivoController;
use App\Http\Controllers\Api\V1\ExpedienteController;
use App\Http\Controllers\Api\V1\LoginController;
use App\Http\Controllers\Api\V1\LogoutController;
use App\Http\Controllers\Api\V1\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\ResetPasswordController;
use App\Http\Controllers\Api\V1\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/status', SystemStatusController::class);

    Route::post('/register', RegisterController::class)->middleware('throttle:5,1');
    Route::post('/login', LoginController::class)->middleware('throttle:5,1');
    Route::post('/forgot-password', PasswordResetLinkController::class)->middleware('throttle:3,1');
    Route::post('/reset-password', ResetPasswordController::class)->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', CurrentUserController::class);
        Route::post('/logout', LogoutController::class);

        Route::get('/expedientes', [ExpedienteController::class, 'index']);
        Route::post('/expedientes', [ExpedienteController::class, 'store']);
        Route::get('/expedientes/{expediente}', [ExpedienteController::class, 'show']);
        Route::patch('/expedientes/{expediente}', [ExpedienteController::class, 'update']);
        Route::delete('/expedientes/{expediente}', [ExpedienteController::class, 'destroy']);
        Route::post('/expedientes/{expediente}/archivos', [ExpedienteArchivoController::class, 'store']);
        Route::get('/expedientes/{expediente}/archivos/{archivo}/descarga', [ExpedienteArchivoController::class, 'download']);
        Route::get('/expedientes/{expediente}/archivos/{archivo}/paginas', [ExpedienteArchivoController::class, 'pages']);
        Route::delete('/expedientes/{expediente}/archivos/{archivo}', [ExpedienteArchivoController::class, 'destroy']);
    });
});
