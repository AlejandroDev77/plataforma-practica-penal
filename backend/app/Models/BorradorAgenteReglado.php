<?php

namespace App\Models;

final class BorradorAgenteReglado extends RegistroJuridicoInmutable
{
    protected $table = 'borradores_agentes_reglados';

    protected $primaryKey = 'id_borrador';

    protected function casts(): array
    {
        return [...parent::casts(), 'id_simulacion' => 'integer', 'id_movimiento' => 'integer',
            'id_etapa' => 'integer', 'id_propuesta_turno' => 'integer', 'orden' => 'integer',
            'ids_fragmento' => 'array', 'preparacion' => 'array', 'respuesta' => 'array',
            'autorizar_externo' => 'boolean'];
    }
}
