<?php

namespace App\Models;

class ObjetoPendienteEliminacion extends ModeloDominio
{
    protected $table = 'objetos_pendientes_eliminacion';

    protected $primaryKey = 'id_objeto_pendiente';

    protected $fillable = [
        'disco',
        'ruta_almacenamiento',
        'estado',
        'intentos',
        'ultimo_error',
    ];

    protected $hidden = ['ruta_almacenamiento', 'disco'];

    protected function casts(): array
    {
        return [
            'intentos' => 'integer',
        ];
    }
}
