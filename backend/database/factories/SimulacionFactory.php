<?php

namespace Database\Factories;

use App\Models\AnalisisExpediente;
use App\Models\Expediente;
use App\Models\Simulacion;
use App\Models\TipoAudiencia;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Simulacion> */
class SimulacionFactory extends Factory
{
    protected $model = Simulacion::class;

    public function definition(): array
    {
        return [
            'id_expediente' => Expediente::factory(),
            'id_usuario' => fn (array $a) => Expediente::findOrFail($a['id_expediente'])->id_usuario,
            'id_analisis' => fn (array $a) => AnalisisExpediente::firstOrCreate(
                ['id_expediente' => $a['id_expediente'], 'version' => 1],
                ['estado' => 'procesado', 'resumen' => 'Contenido exclusivamente de prueba.', 'fecha_analisis' => now()],
            )->getKey(),
            'id_tipo_audiencia' => fn () => TipoAudiencia::firstOrCreate(
                ['codigo' => 'medidas_cautelares'], ['nombre' => 'Medidas cautelares', 'activo' => true],
            )->getKey(),
            'rol_usuario' => 'abogado_defensor',
            'estado' => 'preparando',
        ];
    }
}
