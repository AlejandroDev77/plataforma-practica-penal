<?php

use App\Http\Controllers\Api\V1\AnalisisExpedienteController;
use App\Http\Controllers\Api\V1\AnalisisInternoController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\ExpedienteArchivoController;
use App\Http\Controllers\Api\V1\ExpedienteController;
use App\Http\Controllers\Api\V1\LoginController;
use App\Http\Controllers\Api\V1\LogoutController;
use App\Http\Controllers\Api\V1\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\PropuestaTurnoAudienciaController;
use App\Http\Controllers\Api\V1\RecuperacionExpedienteController;
use App\Http\Controllers\Api\V1\RecuperacionInternaController;
use App\Http\Controllers\Api\V1\RecuperacionJuridicaController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\ResetPasswordController;
use App\Http\Controllers\Api\V1\SimulacionController;
use App\Http\Controllers\Api\V1\SystemStatusController;
use App\Http\Controllers\Api\V1\TipoAudienciaController;
use App\Http\Middleware\VerificarTokenServicioInterno;
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
        Route::get('/expedientes/{expediente}/analisis', [AnalisisExpedienteController::class, 'index']);
        Route::post('/expedientes/{expediente}/analisis', [AnalisisExpedienteController::class, 'store']);
        Route::get('/expedientes/{expediente}/analisis/procesos/{proceso}', [AnalisisExpedienteController::class, 'processStatus']);
        Route::post('/expedientes/{expediente}/analisis/{analisis}/revisiones', [AnalisisExpedienteController::class, 'storeRevision']);
        Route::post('/expedientes/{expediente}/recuperacion', [RecuperacionExpedienteController::class, 'search']);
        Route::post('/expedientes/{expediente}/recuperacion/indexar', [RecuperacionExpedienteController::class, 'indexar']);
        Route::post('/recuperacion-juridica', [RecuperacionJuridicaController::class, 'search']);

        Route::get('/tipos-audiencia', [TipoAudienciaController::class, 'index']);
        Route::get('/administracion/propuestas-turnos', [PropuestaTurnoAudienciaController::class, 'index']);
        Route::post('/administracion/propuestas-turnos', [PropuestaTurnoAudienciaController::class, 'store']);
        Route::put('/administracion/propuestas-turnos/{propuesta}', [PropuestaTurnoAudienciaController::class, 'update']);
        Route::delete('/administracion/propuestas-turnos/{propuesta}', [PropuestaTurnoAudienciaController::class, 'destroy']);
        Route::get('/expedientes/{expediente}/simulaciones', [SimulacionController::class, 'index']);
        Route::post('/expedientes/{expediente}/simulaciones', [SimulacionController::class, 'store']);
        Route::get('/simulaciones/{simulacion}', [SimulacionController::class, 'show']);
        Route::post('/simulaciones/{simulacion}/fuentes', [SimulacionController::class, 'fuentes']);
        Route::post('/simulaciones/{simulacion}/intervenciones', [SimulacionController::class, 'storeIntervencion']);
        Route::post('/simulaciones/{simulacion}/avanzar', [SimulacionController::class, 'avanzar']);
    });
});

Route::prefix('internal/v1')->middleware(VerificarTokenServicioInterno::class)->group(function (): void {
    Route::post('/expedientes/{expediente}/analisis', [AnalisisInternoController::class, 'store']);
    Route::post('/fuentes-juridicas/{fuente}/indexar', [RecuperacionInternaController::class, 'indexarFuenteJuridica']);
});
