<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FragmentoDocumento extends ModeloDominio
{
    public const UPDATED_AT = null;

    protected $table = 'fragmentos_documento';

    protected $primaryKey = 'id_fragmento';

    protected $fillable = [
        'id_archivo',
        'id_fuente_juridica',
        'id_pagina',
        'contenido',
        'numero_pagina',
        'indice_fragmento',
        'cantidad_tokens',
        'metadatos',
        'modelo_embedding',
        'dimensiones_embedding',
    ];

    protected function casts(): array
    {
        return [
            'id_archivo' => 'integer',
            'id_fuente_juridica' => 'integer',
            'id_pagina' => 'integer',
            'numero_pagina' => 'integer',
            'indice_fragmento' => 'integer',
            'cantidad_tokens' => 'integer',
            'metadatos' => 'array',
            'dimensiones_embedding' => 'integer',
        ];
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoExpediente::class, 'id_archivo', 'id_archivo');
    }

    public function fuenteJuridica(): BelongsTo
    {
        return $this->belongsTo(FuenteJuridica::class, 'id_fuente_juridica', 'id_fuente');
    }

    public function pagina(): BelongsTo
    {
        return $this->belongsTo(PaginaExpediente::class, 'id_pagina', 'id_pagina');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(FuenteIntervencion::class, 'id_fragmento', 'id_fragmento');
    }
}
