<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expediente extends ModeloDominio
{
    use HasFactory;

    protected $table = 'expedientes';

    protected $primaryKey = 'id_expediente';

    protected $fillable = [
        'titulo',
        'descripcion',
        'numero_caso',
        'estado',
        'estado_procesamiento',
    ];

    protected function casts(): array
    {
        return [
            'id_usuario' => 'integer',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario', 'id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(ArchivoExpediente::class, 'id_expediente', 'id_expediente');
    }

    public function analisis(): HasMany
    {
        return $this->hasMany(AnalisisExpediente::class, 'id_expediente', 'id_expediente');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(ParticipanteExpediente::class, 'id_expediente', 'id_expediente');
    }

    public function hechos(): HasMany
    {
        return $this->hasMany(HechoExpediente::class, 'id_expediente', 'id_expediente');
    }

    public function delitos(): HasMany
    {
        return $this->hasMany(DelitoExpediente::class, 'id_expediente', 'id_expediente');
    }

    public function pruebas(): HasMany
    {
        return $this->hasMany(PruebaExpediente::class, 'id_expediente', 'id_expediente');
    }

    public function cronologia(): HasMany
    {
        return $this->hasMany(CronologiaExpediente::class, 'id_expediente', 'id_expediente')->orderBy('orden');
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(IncidenciaAnalisis::class, 'id_expediente', 'id_expediente');
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaExpediente::class, 'id_expediente', 'id_expediente');
    }

    public function procesamientos(): HasMany
    {
        return $this->hasMany(HistorialProcesamiento::class, 'id_expediente', 'id_expediente');
    }

    public function simulaciones(): HasMany
    {
        return $this->hasMany(Simulacion::class, 'id_expediente', 'id_expediente');
    }
}
