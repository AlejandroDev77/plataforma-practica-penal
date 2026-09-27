<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class FuenteJuridica extends ModeloDominio
{
    protected $table = 'fuentes_juridicas';

    protected $primaryKey = 'id_fuente';

    protected $fillable = [
        'titulo',
        'tipo_fuente',
        'numero_norma',
        'version',
        'fecha_vigencia',
        'fecha_fin_vigencia',
        'contenido_original',
        'disco',
        'ruta_archivo',
        'estado',
        'validada',
    ];

    protected $hidden = ['ruta_archivo', 'disco'];

    protected function casts(): array
    {
        return [
            'fecha_vigencia' => 'immutable_date',
            'fecha_fin_vigencia' => 'immutable_date',
            'validada' => 'boolean',
        ];
    }

    public function fragmentos(): HasMany
    {
        return $this->hasMany(FragmentoDocumento::class, 'id_fuente_juridica', 'id_fuente');
    }
}
