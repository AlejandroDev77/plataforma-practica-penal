<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AnalisisExpediente extends ModeloDominio
{
    protected $table = 'analisis_expediente';

    protected $primaryKey = 'id_analisis';

    protected $fillable = [
        'id_expediente',
        'resumen',
        'etapa_procesal',
        'estado',
        'version',
        'datos_estructurados',
        'modelo_ia',
        'fecha_analisis',
    ];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'version' => 'integer',
            'datos_estructurados' => 'array',
            'fecha_analisis' => 'immutable_datetime',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class, 'id_expediente', 'id_expediente');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(ParticipanteExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function delitos(): HasMany
    {
        return $this->hasMany(DelitoExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function hechos(): HasMany
    {
        return $this->hasMany(HechoExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function pruebas(): HasMany
    {
        return $this->hasMany(PruebaExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function cronologia(): HasMany
    {
        return $this->hasMany(CronologiaExpediente::class, 'id_analisis', 'id_analisis')->orderBy('orden');
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(IncidenciaAnalisis::class, 'id_analisis', 'id_analisis');
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionAnalisis::class, 'id_analisis', 'id_analisis')
            ->orderByDesc('fecha_creacion')
            ->orderByDesc('id_revision');
    }

    public function revisionActual(): HasOne
    {
        return $this->hasOne(RevisionAnalisis::class, 'id_analisis', 'id_analisis')
            ->latestOfMany('fecha_creacion');
    }

    public function simulaciones(): HasMany
    {
        return $this->hasMany(Simulacion::class, 'id_analisis', 'id_analisis');
    }
}
