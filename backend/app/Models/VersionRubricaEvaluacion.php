<?php

namespace App\Models;

final class VersionRubricaEvaluacion extends RegistroRubricaInmutable
{
    protected $table = 'versiones_rubricas_evaluacion';

    protected $primaryKey = 'id_version_rubrica';
}
