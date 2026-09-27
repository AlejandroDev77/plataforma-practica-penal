<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
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
    });
});
