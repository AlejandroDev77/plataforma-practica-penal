<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenciaExpediente extends ModeloDominio
{
    public const UPDATED_AT = null;

    protected $table = 'referencias_expediente';

    protected $primaryKey = 'id_referencia';

    protected $fillable = [
        'id_expediente',
        'id_analisis',
        'id_archivo',
        'id_pagina',
        'id_participante',
        'id_delito',
        'id_hecho',
        'id_prueba',
        'id_evento',
        'id_incidencia',
        'texto_fuente',
        'nivel_confianza',
    ];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'id_analisis' => 'integer',
            'id_archivo' => 'integer',
            'id_pagina' => 'integer',
            'id_participante' => 'integer',
            'id_delito' => 'integer',
            'id_hecho' => 'integer',
            'id_prueba' => 'integer',
            'id_evento' => 'integer',
            'id_incidencia' => 'integer',
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

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoExpediente::class, 'id_archivo', 'id_archivo');
    }

    public function pagina(): BelongsTo
    {
        return $this->belongsTo(PaginaExpediente::class, 'id_pagina', 'id_pagina');
    }

    public function participante(): BelongsTo
    {
        return $this->belongsTo(ParticipanteExpediente::class, 'id_participante', 'id_participante');
    }

    public function delito(): BelongsTo
    {
        return $this->belongsTo(DelitoExpediente::class, 'id_delito', 'id_delito');
    }

    public function hecho(): BelongsTo
    {
        return $this->belongsTo(HechoExpediente::class, 'id_hecho', 'id_hecho');
    }

    public function prueba(): BelongsTo
    {
        return $this->belongsTo(PruebaExpediente::class, 'id_prueba', 'id_prueba');
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(CronologiaExpediente::class, 'id_evento', 'id_evento');
    }

    public function incidencia(): BelongsTo
    {
        return $this->belongsTo(IncidenciaAnalisis::class, 'id_incidencia', 'id_incidencia');
    }
}
