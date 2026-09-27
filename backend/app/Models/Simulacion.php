<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Simulacion extends ModeloDominio
{
    use HasFactory;

    protected $table = 'simulaciones';

    protected $primaryKey = 'id_simulacion';

    protected $fillable = [
        'id_expediente',
        'id_analisis',
        'id_tipo_audiencia',
        'id_etapa_actual',
        'rol_usuario',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'configuracion',
    ];

    protected function casts(): array
    {
        return [
            'id_usuario' => 'integer',
            'id_expediente' => 'integer',
            'id_analisis' => 'integer',
            'id_tipo_audiencia' => 'integer',
            'id_etapa_actual' => 'integer',
            'fecha_inicio' => 'immutable_datetime',
            'fecha_fin' => 'immutable_datetime',
            'configuracion' => 'array',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario', 'id');
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class, 'id_expediente', 'id_expediente');
    }

    public function analisis(): BelongsTo
    {
        return $this->belongsTo(AnalisisExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function tipoAudiencia(): BelongsTo
    {
        return $this->belongsTo(TipoAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function etapaActual(): BelongsTo
    {
        return $this->belongsTo(EtapaAudiencia::class, 'id_etapa_actual', 'id_etapa');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(ParticipanteSimulacion::class, 'id_simulacion', 'id_simulacion');
    }

    public function intervenciones(): HasMany
    {
        return $this->hasMany(Intervencion::class, 'id_simulacion', 'id_simulacion')->orderBy('orden');
    }

    public function evaluaciones(): HasMany
    {
        return $this->hasMany(Evaluacion::class, 'id_simulacion', 'id_simulacion');
    }
}
