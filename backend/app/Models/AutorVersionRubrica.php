<?php

namespace App\Models;

final class AutorVersionRubrica extends RegistroRubricaInmutable
{
    protected $table = 'autores_version_rubrica';

    protected $primaryKey = 'id_autor_version_rubrica';
}
