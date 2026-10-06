<?php

namespace App\Models;

final class RetiroRevisionRubrica extends RegistroRubricaInmutable
{
    protected $table = 'retiros_revision_rubricas';

    protected $primaryKey = 'id_retiro_revision_rubrica';
}
