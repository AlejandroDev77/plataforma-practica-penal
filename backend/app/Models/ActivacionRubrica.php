<?php

namespace App\Models;

final class ActivacionRubrica extends RegistroRubricaInmutable
{
    protected $table = 'activaciones_rubricas';

    protected $primaryKey = 'id_activacion_rubrica';

    protected function casts(): array
    {
        return [...parent::casts(), 'id_activacion_previa' => 'integer'];
    }
}
