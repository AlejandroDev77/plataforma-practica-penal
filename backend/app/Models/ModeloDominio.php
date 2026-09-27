<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class ModeloDominio extends Model
{
    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = 'fecha_actualizacion';
}
