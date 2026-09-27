<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CriterioRubrica extends ModeloDominio
{
    protected $table = 'criterios_rubrica';

    protected $primaryKey = 'id_criterio';

    protected $fillable = [
        'id_rubrica',
        'nombre',
        'descripcion',
        'peso',
        'puntaje_maximo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'id_rubrica' => 'integer',
            'peso' => 'decimal:4',
            'puntaje_maximo' => 'decimal:4',
            'orden' => 'integer',
        ];
    }

    public function rubrica(): BelongsTo
    {
        return $this->belongsTo(Rubrica::class, 'id_rubrica', 'id_rubrica');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(ResultadoEvaluacion::class, 'id_criterio', 'id_criterio');
    }
}
