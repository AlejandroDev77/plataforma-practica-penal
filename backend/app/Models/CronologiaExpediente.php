<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CronologiaExpediente extends ModeloDominio
{
    protected $table = 'cronologia_expediente';

    protected $primaryKey = 'id_evento';

    protected $fillable = [
        'id_expediente',
        'id_analisis',
        'fecha_evento',
        'titulo',
        'descripcion',
        'orden',
        'nivel_confianza',
    ];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'id_analisis' => 'integer',
            'fecha_evento' => 'immutable_date',
            'orden' => 'integer',
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
        return $this->hasMany(ReferenciaExpediente::class, 'id_evento', 'id_evento');
    }
}
