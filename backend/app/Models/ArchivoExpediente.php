<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArchivoExpediente extends ModeloDominio
{
    use HasFactory;

    protected $table = 'archivos_expediente';

    protected $primaryKey = 'id_archivo';

    protected $fillable = [
        'id_expediente',
        'nombre_original',
        'nombre_almacenado',
        'tipo_mime',
        'extension',
        'disco',
        'ruta_almacenamiento',
        'tamano_bytes',
        'cantidad_paginas',
        'requiere_ocr',
        'estado_procesamiento',
        'mensaje_error',
    ];

    protected $hidden = ['ruta_almacenamiento', 'disco', 'nombre_almacenado'];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'tamano_bytes' => 'integer',
            'cantidad_paginas' => 'integer',
            'requiere_ocr' => 'boolean',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class, 'id_expediente', 'id_expediente');
    }

    public function paginas(): HasMany
    {
        return $this->hasMany(PaginaExpediente::class, 'id_archivo', 'id_archivo')->orderBy('numero_pagina');
    }

    public function fragmentos(): HasMany
    {
        return $this->hasMany(FragmentoDocumento::class, 'id_archivo', 'id_archivo');
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaExpediente::class, 'id_archivo', 'id_archivo');
    }

    public function procesamientos(): HasMany
    {
        return $this->hasMany(HistorialProcesamiento::class, 'id_archivo', 'id_archivo');
    }
}
