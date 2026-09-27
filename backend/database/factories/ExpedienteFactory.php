<?php

namespace Database\Factories;

use App\Models\Expediente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expediente> */
class ExpedienteFactory extends Factory
{
    protected $model = Expediente::class;

    public function definition(): array
    {
        return [
            'id_usuario' => User::factory(),
            'titulo' => 'Expediente de prueba '.fake()->uuid(),
            'estado' => 'borrador',
            'estado_procesamiento' => 'pendiente',
        ];
    }
}
