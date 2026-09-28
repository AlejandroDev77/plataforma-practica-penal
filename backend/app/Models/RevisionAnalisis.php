<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionAnalisis extends ModeloDominio
{
    public const UPDATED_AT = null;

    protected $table = 'revisiones_analisis';

    protected $primaryKey = 'id_revision';

    protected $fillable = [
        'id_expediente',
        'id_analisis',
        'id_usuario',
        'decision',
        'observacion',
    ];

    protected function casts(): array
    {
        return [
            'id_expediente' => 'integer',
            'id_analisis' => 'integer',
            'id_usuario' => 'integer',
            'fecha_creacion' => 'immutable_datetime',
        ];
    }

    public function analisis(): BelongsTo
    {
        return $this->belongsTo(AnalisisExpediente::class, 'id_analisis', 'id_analisis');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario', 'id');
    }
}
