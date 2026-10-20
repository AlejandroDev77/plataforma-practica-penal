<?php

namespace App\Models;

abstract class RegistroRubricaInmutable extends RegistroJuridicoInmutable
{
    protected function casts(): array
    {
        return [...parent::casts(), 'id_rubrica' => 'integer', 'id_version_rubrica' => 'integer',
            'id_habilitacion_rubrica' => 'integer', 'id_revision_rubrica' => 'integer', 'id_revision_previa' => 'integer',
            'id_usuario_preparador' => 'integer', 'id_usuario_autor' => 'integer', 'numero_version' => 'integer', 'contenido' => 'array',
            'ids_autores_declarados' => 'array', 'confirmacion_autoria_declarada' => 'boolean', 'autoria_revisada' => 'boolean'];
    }
}
