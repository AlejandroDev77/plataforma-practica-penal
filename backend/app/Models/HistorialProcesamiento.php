<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialProcesamiento extends ModeloDominio
{
    protected $table = 'historial_procesamiento';

    protected $primaryKey = 'id_proceso';

    protected $fillable = [
        'id_expediente',
        'id_archivo',
        'id_analisis',
        'tipo',
        'estado',
        'intento',
        'mensaje_error',
        'metadatos',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'id_archivo' => 'integer',
            'id_analisis' => 'integer',
            'intento' => 'integer',
            'metadatos' => 'array',
            'fecha_inicio' => 'immutable_datetime',
            'fecha_fin' => 'immutable_datetime',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class, 'id_expediente', 'id_expediente');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoExpediente::class, 'id_archivo', 'id_archivo');
    }

    public function analisis(): BelongsTo
    {
        return $this->belongsTo(AnalisisExpediente::class, 'id_analisis', 'id_analisis');
    }
}
