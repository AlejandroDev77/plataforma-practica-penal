<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransicionAudiencia extends ModeloDominio
{
    protected $table = 'transiciones_audiencia';

    protected $primaryKey = 'id_transicion';

    protected $fillable = [
        'id_tipo_audiencia',
        'id_etapa_origen',
        'id_etapa_destino',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'id_tipo_audiencia' => 'integer',
            'id_etapa_origen' => 'integer',
            'id_etapa_destino' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function tipoAudiencia(): BelongsTo
    {
        return $this->belongsTo(TipoAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(EtapaAudiencia::class, 'id_etapa_origen', 'id_etapa');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(EtapaAudiencia::class, 'id_etapa_destino', 'id_etapa');
    }
}
