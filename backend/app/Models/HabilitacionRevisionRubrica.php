<?php

namespace App\Models;

final class HabilitacionRevisionRubrica extends RegistroRubricaInmutable
{
    protected $table = 'habilitaciones_revision_rubricas';

    protected $primaryKey = 'id_habilitacion_rubrica';
}
