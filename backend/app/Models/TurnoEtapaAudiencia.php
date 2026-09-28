<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TurnoEtapaAudiencia extends ModeloDominio
{
    protected $table = 'turnos_etapa_audiencia';

    protected $primaryKey = 'id_turno_etapa';

    protected $fillable = [
        'id_tipo_audiencia',
        'id_etapa',
        'orden',
        'rol',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'id_tipo_audiencia' => 'integer',
            'id_etapa' => 'integer',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaAudiencia::class, 'id_etapa', 'id_etapa');
    }

    public function tipoAudiencia(): BelongsTo
    {
        return $this->belongsTo(TipoAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }
}
