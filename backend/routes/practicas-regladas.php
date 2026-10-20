<?php

use App\Http\Controllers\Api\V1\PracticaRegladaController;
use App\Http\Controllers\Api\V1\EvaluacionRegladaController;
use App\Http\Controllers\Api\V1\ContextoEvaluacionRegladaController;
use App\Http\Controllers\Api\V1\PreparacionEvaluacionRegladaController;
use App\Http\Middleware\ResponderPracticaReglada;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/practicas-regladas')->middleware([ResponderPracticaReglada::class, 'auth:sanctum'])->group(function (): void {
    Route::post('/expedientes/{expediente}', [PracticaRegladaController::class, 'crear'])->whereNumber('expediente')->middleware('throttle:practicas-regladas-comandos');
    Route::get('/{practica}/orquestacion', [PracticaRegladaController::class, 'consultar'])->whereNumber('practica');
    Route::get('/{practica}/acta', [PracticaRegladaController::class, 'acta'])->whereNumber('practica');
    Route::get('/{practica}/preparacion-evaluacion', PreparacionEvaluacionRegladaController::class)->whereNumber('practica');
    Route::get('/{practica}/contexto-evaluacion', ContextoEvaluacionRegladaController::class)->whereNumber('practica');
    Route::get('/{practica}/evaluacion', [EvaluacionRegladaController::class, 'latest'])->whereNumber('practica');
    Route::post('/{practica}/evaluacion', [EvaluacionRegladaController::class, 'store'])->whereNumber('practica')->middleware('throttle:3,1');
    Route::get('/{practica}/evaluaciones/{evaluacion}', [EvaluacionRegladaController::class, 'show'])->whereNumber(['practica', 'evaluacion']);
    Route::post('/{practica}/fuentes', [PracticaRegladaController::class, 'fuentes'])->whereNumber('practica');
    Route::get('/{practica}/proveedor', [PracticaRegladaController::class, 'proveedor'])->whereNumber('practica')->middleware('throttle:practicas-regladas-proveedor');
    Route::post('/{practica}/movimientos', [PracticaRegladaController::class, 'mover'])->whereNumber('practica')->middleware('throttle:practicas-regladas-comandos');
    Route::post('/{practica}/defensa', [PracticaRegladaController::class, 'defensa'])->whereNumber('practica')->middleware('throttle:practicas-regladas-comandos');
    Route::post('/{practica}/borradores', [PracticaRegladaController::class, 'proponer'])->whereNumber('practica')->middleware('throttle:practicas-regladas-generacion');
    Route::get('/{practica}/borradores', [PracticaRegladaController::class, 'borradores'])->whereNumber('practica');
    Route::get('/{practica}/borradores/{borrador}', [PracticaRegladaController::class, 'borrador'])->whereNumber(['practica', 'borrador']);
    Route::post('/{practica}/borradores/{borrador}/confirmacion', [PracticaRegladaController::class, 'confirmar'])->whereNumber(['practica', 'borrador'])->middleware('throttle:practicas-regladas-comandos');
});
