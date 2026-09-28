<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EtapaAudiencia extends ModeloDominio
{
    protected $table = 'etapas_audiencia';

    protected $primaryKey = 'id_etapa';

    protected $fillable = [
        'id_tipo_audiencia',
        'codigo',
        'nombre',
        'descripcion',
        'orden',
        'es_inicial',
        'es_final',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'id_tipo_audiencia' => 'integer',
            'orden' => 'integer',
            'es_inicial' => 'boolean',
            'es_final' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function tipoAudiencia(): BelongsTo
    {
        return $this->belongsTo(TipoAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function transicionesSalientes(): HasMany
    {
        return $this->hasMany(TransicionAudiencia::class, 'id_etapa_origen', 'id_etapa');
    }

    public function transicionesEntrantes(): HasMany
    {
        return $this->hasMany(TransicionAudiencia::class, 'id_etapa_destino', 'id_etapa');
    }

    public function turnosConfigurados(): HasMany
    {
        return $this->hasMany(TurnoEtapaAudiencia::class, 'id_etapa', 'id_etapa')
            ->where('activo', true)
            ->orderBy('orden');
    }
}
