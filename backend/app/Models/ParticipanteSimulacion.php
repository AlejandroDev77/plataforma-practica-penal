<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParticipanteSimulacion extends ModeloDominio
{
    protected $table = 'participantes_simulacion';

    protected $primaryKey = 'id_participante_simulacion';

    protected $fillable = [
        'id_simulacion',
        'id_analisis',
        'id_expediente',
        'id_participante_expediente',
        'rol',
        'nombre_mostrado',
        'controlado_por',
        'estado',
        'configuracion',
    ];

    protected function casts(): array
    {
        return [
            'id_simulacion' => 'integer',
            'id_analisis' => 'integer',
            'id_expediente' => 'integer',
            'id_participante_expediente' => 'integer',
            'configuracion' => 'array',
        ];
    }

    public function simulacion(): BelongsTo
    {
        return $this->belongsTo(Simulacion::class, 'id_simulacion', 'id_simulacion');
    }

    public function participanteExpediente(): BelongsTo
    {
        return $this->belongsTo(ParticipanteExpediente::class, 'id_participante_expediente', 'id_participante');
    }

    public function analisis(): BelongsTo
    {
        return $this->belongsTo(AnalisisExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class, 'id_expediente', 'id_expediente');
    }

    public function intervenciones(): HasMany
    {
        return $this->hasMany(Intervencion::class, 'id_participante_simulacion', 'id_participante_simulacion')->orderBy('orden');
    }
}
