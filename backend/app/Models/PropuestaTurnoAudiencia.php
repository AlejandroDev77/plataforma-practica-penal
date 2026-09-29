<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PropuestaTurnoAudiencia extends ModeloDominio
{
    protected $table = 'propuestas_turnos_audiencia';

    protected $primaryKey = 'id_propuesta_turno';

    protected $fillable = [
        'id_tipo_audiencia',
        'id_etapa',
        'orden',
        'rol',
        'acto_propuesto',
        'descripcion_propuesta',
        'referencia_normativa_propuesta',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'id_tipo_audiencia' => 'integer',
            'id_etapa' => 'integer',
            'orden' => 'integer',
        ];
    }

    public function tipoAudiencia(): BelongsTo
    {
        return $this->belongsTo(TipoAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaAudiencia::class, 'id_etapa', 'id_etapa');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario_creador');
    }
}
