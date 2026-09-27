<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

final class ConcederAdministracion extends Command
{
    protected $signature = 'jurissim:conceder-administracion {email : Correo de una cuenta ya registrada}';

    protected $description = 'Concede el rol de administrador de plataforma a una cuenta existente';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No encontramos una cuenta con ese correo. Regístrela primero.');

            return self::FAILURE;
        }

        $role = Role::findOrCreate('administrador_plataforma', 'web');

        if ($user->hasRole($role)) {
            $this->info('La cuenta ya tiene acceso de administrador de plataforma.');

            return self::SUCCESS;
        }

        if (! $this->confirm("¿Conceder acceso de administrador de plataforma a {$user->email}?")) {
            $this->comment('No se realizaron cambios.');

            return self::SUCCESS;
        }

        $user->assignRole($role);
        $this->info('Acceso administrativo concedido. Cierre e inicie sesión para actualizar la sesión.');

        return self::SUCCESS;
    }
}
