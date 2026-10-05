<?php

namespace App\Models;

final class RegistroIntervencionReglada extends RegistroJuridicoInmutable
{
    protected $table = 'registros_intervenciones_regladas';

    protected $primaryKey = 'id_registro';

    protected function casts(): array
    {
        return [...parent::casts(), 'id_intervencion' => 'integer', 'id_simulacion' => 'integer',
            'id_movimiento' => 'integer', 'id_etapa' => 'integer', 'id_propuesta_turno' => 'integer',
            'orden' => 'integer', 'fuentes' => 'array', 'ids_fragmento' => 'array'];
    }
}
