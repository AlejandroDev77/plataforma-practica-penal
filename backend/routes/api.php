<?php

use App\Http\Controllers\Api\V1\AnalisisExpedienteController;
use App\Http\Controllers\Api\V1\AnalisisInternoController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\EvaluacionSimulacionController;
use App\Http\Controllers\Api\V1\ExpedienteArchivoController;
use App\Http\Controllers\Api\V1\ExpedienteController;
use App\Http\Controllers\Api\V1\GobiernoReglasAudienciaController;
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
use App\Http\Middleware\NoAlmacenarReglas;
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
        Route::prefix('reglas-audiencia')->middleware(NoAlmacenarReglas::class)->group(function (): void {
            Route::get('/tipos/{tipo}/preparacion-instrucciones', [GobiernoReglasAudienciaController::class, 'prepararInstrucciones'])->whereNumber('tipo')->middleware('throttle:10,1');
            Route::get('/versiones', [GobiernoReglasAudienciaController::class, 'index']);
            Route::get('/versiones/{version}', [GobiernoReglasAudienciaController::class, 'show'])->whereNumber('version');
            Route::get('/versiones/{version}/revisiones', [GobiernoReglasAudienciaController::class, 'indexRevisiones'])->whereNumber('version');
            Route::get('/versiones/{version}/preparacion-activacion', [GobiernoReglasAudienciaController::class, 'prepararActivacion'])->whereNumber('version')->middleware('throttle:10,1');
            Route::get('/tipos/{tipo}/activaciones', [GobiernoReglasAudienciaController::class, 'indexActivaciones'])->whereNumber('tipo');
            Route::get('/habilitaciones', [GobiernoReglasAudienciaController::class, 'indexHabilitaciones']);
            Route::get('/habilitaciones/{habilitacion}', [GobiernoReglasAudienciaController::class, 'showHabilitacion'])->whereNumber('habilitacion');
            Route::middleware('throttle:10,1')->group(function (): void {
                Route::post('/tipos/{tipo}/versiones', [GobiernoReglasAudienciaController::class, 'storeVersion'])->whereNumber('tipo');
                Route::post('/habilitaciones', [GobiernoReglasAudienciaController::class, 'storeHabilitacion']);
                Route::post('/habilitaciones/{habilitacion}/revocaciones', [GobiernoReglasAudienciaController::class, 'revokeHabilitacion'])->whereNumber('habilitacion');
                Route::post('/versiones/{version}/revisiones', [GobiernoReglasAudienciaController::class, 'storeRevision'])->whereNumber('version');
                Route::post('/revisiones/{revision}/revocaciones', [GobiernoReglasAudienciaController::class, 'revokeRevision'])->whereNumber('revision');
                Route::post('/versiones/{version}/activaciones', [GobiernoReglasAudienciaController::class, 'storeActivacion'])->whereNumber('version');
                Route::post('/activaciones/{activacion}/revocaciones', [GobiernoReglasAudienciaController::class, 'revokeActivacion'])->whereNumber('activacion');
            });
        });
        Route::get('/administracion/propuestas-turnos', [PropuestaTurnoAudienciaController::class, 'index']);
        Route::post('/administracion/propuestas-turnos', [PropuestaTurnoAudienciaController::class, 'store']);
        Route::put('/administracion/propuestas-turnos/{propuesta}', [PropuestaTurnoAudienciaController::class, 'update']);
        Route::delete('/administracion/propuestas-turnos/{propuesta}', [PropuestaTurnoAudienciaController::class, 'destroy']);
        Route::get('/expedientes/{expediente}/simulaciones', [SimulacionController::class, 'index']);
        Route::post('/expedientes/{expediente}/simulaciones', [SimulacionController::class, 'store']);
        Route::get('/simulaciones/{simulacion}', [SimulacionController::class, 'show']);
        Route::get('/simulaciones/{simulacion}/evaluacion', [EvaluacionSimulacionController::class, 'latest']);
        Route::post('/simulaciones/{simulacion}/fuentes', [SimulacionController::class, 'fuentes']);
        Route::post('/simulaciones/{simulacion}/propuesta-intervencion', [SimulacionController::class, 'proponerIntervencion'])
            ->middleware('throttle:5,1');
        Route::get('/simulaciones/{simulacion}/proveedor-generacion', [SimulacionController::class, 'proveedorGeneracion']);
        Route::post('/simulaciones/{simulacion}/intervenciones', [SimulacionController::class, 'storeIntervencion']);
        Route::post('/simulaciones/{simulacion}/avanzar', [SimulacionController::class, 'avanzar']);
        Route::post('/simulaciones/{simulacion}/evaluacion', [EvaluacionSimulacionController::class, 'store'])
            ->middleware('throttle:3,1');
        Route::get('/simulaciones/{simulacion}/evaluaciones/{evaluacion}', [EvaluacionSimulacionController::class, 'show']);
    });
});

Route::prefix('internal/v1')->middleware(VerificarTokenServicioInterno::class)->group(function (): void {
    Route::post('/expedientes/{expediente}/analisis', [AnalisisInternoController::class, 'store']);
    Route::post('/fuentes-juridicas/{fuente}/indexar', [RecuperacionInternaController::class, 'indexarFuenteJuridica']);
});

require __DIR__.'/practicas-regladas.php';
