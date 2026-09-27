<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rubrica extends ModeloDominio
{
    protected $table = 'rubricas';

    protected $primaryKey = 'id_rubrica';

    protected $fillable = [
        'nombre',
        'descripcion',
        'rol_aplicable',
        'id_tipo_audiencia',
        'version',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'id_tipo_audiencia' => 'integer',
            'version' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function tipoAudiencia(): BelongsTo
    {
        return $this->belongsTo(TipoAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function criterios(): HasMany
    {
        return $this->hasMany(CriterioRubrica::class, 'id_rubrica', 'id_rubrica')->orderBy('orden');
    }

    public function evaluaciones(): HasMany
    {
        return $this->hasMany(Evaluacion::class, 'id_rubrica', 'id_rubrica');
    }
}
