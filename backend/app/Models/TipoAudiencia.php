<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoAudiencia extends ModeloDominio
{
    protected $table = 'tipos_audiencia';

    protected $primaryKey = 'id_tipo_audiencia';

    protected $fillable = [
        'nombre',
        'codigo',
        'descripcion',
        'activo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia')->orderBy('orden');
    }

    public function transiciones(): HasMany
    {
        return $this->hasMany(TransicionAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function simulaciones(): HasMany
    {
        return $this->hasMany(Simulacion::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function rubricas(): HasMany
    {
        return $this->hasMany(Rubrica::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }
}
