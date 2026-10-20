<?php

namespace App\Models;

use LogicException;

final class ContextoEvaluacionReglada extends ModeloDominio
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'contextos_evaluacion_reglada';

    protected $primaryKey = 'id_contexto_evaluacion';

    protected $fillable = [
        'id_evaluacion', 'id_version_rubrica', 'id_revision_rubrica', 'id_activacion_rubrica',
        'id_usuario_consentimiento', 'proveedor', 'huella_contexto', 'contenido',
        'consentimiento_externo', 'fecha_consentimiento',
    ];

    protected function casts(): array
    {
        return ['contenido' => 'array', 'consentimiento_externo' => 'boolean',
            'fecha_consentimiento' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El contexto consentido no se edita.'));
        static::deleting(fn () => throw new LogicException('El contexto consentido no se elimina.'));
    }
}
