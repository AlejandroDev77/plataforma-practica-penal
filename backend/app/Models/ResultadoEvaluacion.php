<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultadoEvaluacion extends ModeloDominio
{
    protected $table = 'resultados_evaluacion';

    protected $primaryKey = 'id_resultado';

    protected $fillable = [
        'id_evaluacion',
        'id_rubrica',
        'id_criterio',
        'puntaje',
        'comentario',
        'evidencia',
    ];

    protected function casts(): array
    {
        return [
            'id_evaluacion' => 'integer',
            'id_rubrica' => 'integer',
            'id_criterio' => 'integer',
            'puntaje' => 'decimal:4',
        ];
    }

    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(Evaluacion::class, 'id_evaluacion', 'id_evaluacion');
    }

    public function criterio(): BelongsTo
    {
        return $this->belongsTo(CriterioRubrica::class, 'id_criterio', 'id_criterio');
    }

    public function rubrica(): BelongsTo
    {
        return $this->belongsTo(Rubrica::class, 'id_rubrica', 'id_rubrica');
    }
}
