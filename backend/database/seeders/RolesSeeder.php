<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'administrador_plataforma',
            'administrador_institucional',
            'docente',
            'estudiante',
            'revisor',
        ] as $nombre) {
            Role::findOrCreate($nombre, 'web');
        }
    }
}
