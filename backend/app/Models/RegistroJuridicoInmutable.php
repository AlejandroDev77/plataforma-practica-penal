<?php

namespace App\Models;

use LogicException;

abstract class RegistroJuridicoInmutable extends ModeloDominio
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'id_habilitacion' => 'integer', 'id_revision_reglas' => 'integer',
            'id_tipo_audiencia' => 'integer', 'id_version_reglas' => 'integer',
            'id_usuario_revisor' => 'integer', 'id_usuario_habilitador' => 'integer',
            'id_usuario_responsable' => 'integer',
            'fecha_creacion' => 'immutable_datetime', 'fecha_vencimiento' => 'immutable_datetime',
            'vigencia_comprobada_en' => 'immutable_date', 'fuentes_verificadas' => 'array',
            'acreditacion_verificada' => 'boolean', 'confirmacion_humana' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial juridico no se edita.'));
        static::deleting(fn () => throw new LogicException('El historial juridico no se elimina.'));
    }
}
