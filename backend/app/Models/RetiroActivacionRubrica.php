<?php

namespace App\Models;

final class RetiroActivacionRubrica extends RegistroRubricaInmutable
{
    protected $table = 'retiros_activaciones_rubricas';

    protected $primaryKey = 'id_retiro_activacion_rubrica';

    protected function casts(): array
    {
        return [...parent::casts(), 'id_activacion_rubrica' => 'integer'];
    }
}
