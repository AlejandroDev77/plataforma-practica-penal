<?php

namespace App\Models;

final class RevocacionActivacionReglas extends RegistroJuridicoInmutable
{
    protected $table = 'revocaciones_activaciones_reglas';

    protected $primaryKey = 'id_revocacion_activacion';

    protected function casts(): array
    {
        return [...parent::casts(), 'id_activacion' => 'integer'];
    }
}
