<?php

namespace App\Models;

final class HabilitacionRevisionJuridica extends RegistroJuridicoInmutable
{
    protected $table = 'habilitaciones_revision_juridica';

    protected $primaryKey = 'id_habilitacion';
}
