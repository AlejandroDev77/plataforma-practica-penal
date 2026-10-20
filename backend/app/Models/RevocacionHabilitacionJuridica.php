<?php

namespace App\Models;

final class RevocacionHabilitacionJuridica extends RegistroJuridicoInmutable
{
    protected $table = 'revocaciones_habilitacion_juridica';

    protected $primaryKey = 'id_revocacion_habilitacion';
}
