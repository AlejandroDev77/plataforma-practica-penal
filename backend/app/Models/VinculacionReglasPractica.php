<?php

namespace App\Models;

final class VinculacionReglasPractica extends RegistroJuridicoInmutable
{
    protected $table = 'vinculaciones_reglas_practica';

    protected $primaryKey = 'id_vinculacion';

    protected function casts(): array
    {
        return [...parent::casts(), 'id_simulacion' => 'integer', 'id_activacion' => 'integer'];
    }
}
