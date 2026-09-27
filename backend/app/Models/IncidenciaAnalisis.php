<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidenciaAnalisis extends ModeloDominio
{
    protected $table = 'incidencias_analisis';

    protected $primaryKey = 'id_incidencia';

    protected $fillable = [
        'id_expediente',
        'id_analisis',
        'tipo',
        'descripcion',
        'gravedad',
        'resuelta',
        'datos_adicionales',
    ];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'id_analisis' => 'integer',
            'resuelta' => 'boolean',
            'datos_adicionales' => 'array',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class, 'id_expediente', 'id_expediente');
    }

    public function analisis(): BelongsTo
    {
        return $this->belongsTo(AnalisisExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaExpediente::class, 'id_incidencia', 'id_incidencia');
    }
}
