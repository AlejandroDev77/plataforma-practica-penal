<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuenteIntervencion extends ModeloDominio
{
    public const UPDATED_AT = null;

    protected $table = 'fuentes_intervencion';

    protected $primaryKey = 'id_fuente_intervencion';

    protected $fillable = [
        'id_intervencion',
        'id_fragmento',
        'fragmento_utilizado',
        'metadatos',
    ];

    protected function casts(): array
    {
        return [
            'id_intervencion' => 'integer',
            'id_fragmento' => 'integer',
            'metadatos' => 'array',
        ];
    }

    public function intervencion(): BelongsTo
    {
        return $this->belongsTo(Intervencion::class, 'id_intervencion', 'id_intervencion');
    }

    public function fragmento(): BelongsTo
    {
        return $this->belongsTo(FragmentoDocumento::class, 'id_fragmento', 'id_fragmento');
    }
}
