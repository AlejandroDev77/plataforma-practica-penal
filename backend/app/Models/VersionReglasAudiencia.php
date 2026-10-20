<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class VersionReglasAudiencia extends ModeloDominio
{
    public const UPDATED_AT = null;

    protected $table = 'versiones_reglas_audiencia';

    protected $primaryKey = 'id_version_reglas';

    // La autoria y el contenido se establecen exclusivamente desde el servicio.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'id_tipo_audiencia' => 'integer',
            'id_usuario_autor' => 'integer',
            'numero_version' => 'integer',
            'contenido' => 'array',
            'fecha_creacion' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Una version sellada no se puede editar.'));
        self::deleting(fn () => throw new LogicException('Una version sellada no se puede eliminar.'));
    }

    public function tipoAudiencia(): BelongsTo
    {
        return $this->belongsTo(TipoAudiencia::class, 'id_tipo_audiencia', 'id_tipo_audiencia');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario_autor');
    }
}
