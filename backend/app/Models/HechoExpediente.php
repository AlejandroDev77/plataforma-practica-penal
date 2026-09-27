<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HechoExpediente extends ModeloDominio
{
    protected $table = 'hechos_expediente';

    protected $primaryKey = 'id_hecho';

    protected $fillable = [
        'id_expediente',
        'id_analisis',
        'descripcion',
        'tipo_hecho',
        'fecha_hecho',
        'orden_cronologico',
        'confirmado',
        'nivel_confianza',
    ];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'id_analisis' => 'integer',
            'fecha_hecho' => 'immutable_date',
            'orden_cronologico' => 'integer',
            'confirmado' => 'boolean',
            'nivel_confianza' => 'decimal:4',
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
        return $this->hasMany(ReferenciaExpediente::class, 'id_hecho', 'id_hecho');
    }
}
