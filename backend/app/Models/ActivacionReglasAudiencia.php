<?php

namespace App\Models;

final class ActivacionReglasAudiencia extends RegistroJuridicoInmutable
{
    protected $table = 'activaciones_reglas_audiencia';

    protected $primaryKey = 'id_activacion';

    protected function casts(): array
    {
        return [...parent::casts(), 'id_activacion_previa' => 'integer'];
    }
}
