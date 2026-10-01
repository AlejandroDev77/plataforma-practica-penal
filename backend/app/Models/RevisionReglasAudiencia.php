<?php

namespace App\Models;

final class RevisionReglasAudiencia extends RegistroJuridicoInmutable
{
    protected $table = 'revisiones_reglas_audiencia';

    protected $primaryKey = 'id_revision_reglas';
}
