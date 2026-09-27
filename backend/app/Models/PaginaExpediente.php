<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaginaExpediente extends ModeloDominio
{
    protected $table = 'paginas_expediente';

    protected $primaryKey = 'id_pagina';

    protected $fillable = [
        'id_archivo',
        'numero_pagina',
        'localizador',
        'texto_extraido',
        'uso_ocr',
        'nivel_confianza',
        'es_legible',
    ];

    protected function casts(): array
    {
        return [
            'id_archivo' => 'integer',
            'numero_pagina' => 'integer',
            'uso_ocr' => 'boolean',
            'nivel_confianza' => 'decimal:4',
            'es_legible' => 'boolean',
        ];
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoExpediente::class, 'id_archivo', 'id_archivo');
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaExpediente::class, 'id_pagina', 'id_pagina');
    }

    public function fragmentos(): HasMany
    {
        return $this->hasMany(FragmentoDocumento::class, 'id_pagina', 'id_pagina');
    }
}
