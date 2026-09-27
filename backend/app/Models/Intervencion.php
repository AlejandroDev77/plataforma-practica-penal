<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Intervencion extends ModeloDominio
{
    use HasFactory;

    protected $table = 'intervenciones';

    protected $primaryKey = 'id_intervencion';

    protected $fillable = [
        'id_simulacion',
        'id_participante_simulacion',
        'id_tipo_audiencia',
        'id_etapa',
        'orden',
        'contenido',
        'tipo_entrada',
        'modelo_ia',
        'metadatos',
        'fecha_intervencion',
    ];

    protected function casts(): array
    {
        return [
            'id_simulacion' => 'integer',
            'id_participante_simulacion' => 'integer',
            'id_tipo_audiencia' => 'integer',
            'id_etapa' => 'integer',
            'orden' => 'integer',
            'metadatos' => 'array',
            'fecha_intervencion' => 'immutable_datetime',
        ];
    }

    public function simulacion(): BelongsTo
    {
        return $this->belongsTo(Simulacion::class, 'id_simulacion', 'id_simulacion');
    }

    public function participante(): BelongsTo
    {
        return $this->belongsTo(ParticipanteSimulacion::class, 'id_participante_simulacion', 'id_participante_simulacion');
    }

    public function tipoAudiencia(): BelongsTo
    {
        return $this->belongsTo(TipoAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaAudiencia::class, 'id_etapa', 'id_etapa');
    }

    public function fuentes(): HasMany
    {
        return $this->hasMany(FuenteIntervencion::class, 'id_intervencion', 'id_intervencion');
    }
}
