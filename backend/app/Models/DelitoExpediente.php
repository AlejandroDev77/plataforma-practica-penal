<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DelitoExpediente extends ModeloDominio
{
    protected $table = 'delitos_expediente';

    protected $primaryKey = 'id_delito';

    protected $fillable = [
        'id_expediente',
        'id_analisis',
        'nombre_delito',
        'articulo_referido',
        'descripcion',
        'confirmado',
        'nivel_confianza',
    ];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'id_analisis' => 'integer',
            'confirmado' => 'boolean',
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

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaExpediente::class, 'id_delito', 'id_delito');
    }
}
