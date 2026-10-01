<?php

namespace App\Models;

final class RevocacionRevisionReglas extends RegistroJuridicoInmutable
{
    protected $table = 'revocaciones_revision_reglas';

    protected $primaryKey = 'id_revocacion_revision';
}
