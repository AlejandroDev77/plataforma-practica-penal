<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evaluacion extends ModeloDominio
{
    protected $table = 'evaluaciones';

    protected $primaryKey = 'id_evaluacion';

    protected $fillable = [
        'id_simulacion',
        'id_rubrica',
        'estado',
        'puntaje_total',
        'fortalezas',
        'debilidades',
        'recomendaciones',
        'datos_evaluacion',
        'modelo_ia',
        'fecha_evaluacion',
    ];

    protected function casts(): array
    {
        return [
            'id_simulacion' => 'integer',
            'id_rubrica' => 'integer',
            'puntaje_total' => 'decimal:4',
            'datos_evaluacion' => 'array',
            'fecha_evaluacion' => 'immutable_datetime',
        ];
    }

    public function simulacion(): BelongsTo
    {
        return $this->belongsTo(Simulacion::class, 'id_simulacion', 'id_simulacion');
    }

    public function rubrica(): BelongsTo
    {
        return $this->belongsTo(Rubrica::class, 'id_rubrica', 'id_rubrica');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(ResultadoEvaluacion::class, 'id_evaluacion', 'id_evaluacion');
    }
}
