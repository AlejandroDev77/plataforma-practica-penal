<?php

namespace App\Models;

final class MovimientoPracticaReglada extends RegistroJuridicoInmutable
{
    protected $table = 'movimientos_practicas_regladas';

    protected $primaryKey = 'id_movimiento';

    protected function casts(): array
    {
        return [...parent::casts(), 'id_simulacion' => 'integer', 'id_vinculacion' => 'integer',
            'orden' => 'integer', 'id_etapa_origen' => 'integer', 'id_etapa_destino' => 'integer'];
    }
}
