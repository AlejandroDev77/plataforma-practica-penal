<?php

use App\Http\Controllers\Api\V1\PracticaRegladaController;
use App\Http\Controllers\Api\V1\PreparacionEvaluacionRegladaController;
use App\Http\Middleware\ResponderPracticaReglada;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/practicas-regladas')->middleware([ResponderPracticaReglada::class, 'auth:sanctum'])->group(function (): void {
    Route::post('/expedientes/{expediente}', [PracticaRegladaController::class, 'crear'])->whereNumber('expediente')->middleware('throttle:practicas-regladas-comandos');
    Route::get('/{practica}/orquestacion', [PracticaRegladaController::class, 'consultar'])->whereNumber('practica');
    Route::get('/{practica}/acta', [PracticaRegladaController::class, 'acta'])->whereNumber('practica');
    Route::get('/{practica}/preparacion-evaluacion', PreparacionEvaluacionRegladaController::class)->whereNumber('practica');
    Route::post('/{practica}/fuentes', [PracticaRegladaController::class, 'fuentes'])->whereNumber('practica');
    Route::get('/{practica}/proveedor', [PracticaRegladaController::class, 'proveedor'])->whereNumber('practica')->middleware('throttle:practicas-regladas-proveedor');
    Route::post('/{practica}/movimientos', [PracticaRegladaController::class, 'mover'])->whereNumber('practica')->middleware('throttle:practicas-regladas-comandos');
    Route::post('/{practica}/defensa', [PracticaRegladaController::class, 'defensa'])->whereNumber('practica')->middleware('throttle:practicas-regladas-comandos');
    Route::post('/{practica}/borradores', [PracticaRegladaController::class, 'proponer'])->whereNumber('practica')->middleware('throttle:practicas-regladas-generacion');
    Route::get('/{practica}/borradores', [PracticaRegladaController::class, 'borradores'])->whereNumber('practica');
    Route::get('/{practica}/borradores/{borrador}', [PracticaRegladaController::class, 'borrador'])->whereNumber(['practica', 'borrador']);
    Route::post('/{practica}/borradores/{borrador}/confirmacion', [PracticaRegladaController::class, 'confirmar'])->whereNumber(['practica', 'borrador'])->middleware('throttle:practicas-regladas-comandos');
});
