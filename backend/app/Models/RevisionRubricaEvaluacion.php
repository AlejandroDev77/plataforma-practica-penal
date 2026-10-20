<?php

namespace App\Models;

final class RevisionRubricaEvaluacion extends RegistroRubricaInmutable
{
    protected $table = 'revisiones_rubricas_evaluacion';

    protected $primaryKey = 'id_revision_rubrica';
}
