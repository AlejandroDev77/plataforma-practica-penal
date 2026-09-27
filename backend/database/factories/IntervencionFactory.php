<?php

namespace Database\Factories;

use App\Models\EtapaAudiencia;
use App\Models\Intervencion;
use App\Models\ParticipanteSimulacion;
use App\Models\Simulacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Intervencion> */
class IntervencionFactory extends Factory
{
    protected $model = Intervencion::class;

    public function definition(): array
    {
        return [
            'id_simulacion' => Simulacion::factory(),
            'id_tipo_audiencia' => fn (array $a) => Simulacion::findOrFail($a['id_simulacion'])->id_tipo_audiencia,
            'id_etapa' => fn (array $a) => EtapaAudiencia::firstOrCreate(
                ['id_tipo_audiencia' => $a['id_tipo_audiencia'], 'codigo' => 'apertura'],
                ['nombre' => 'Apertura', 'orden' => 1, 'es_inicial' => true, 'activo' => true],
            )->getKey(),
            'id_participante_simulacion' => function (array $a) {
                $simulacion = Simulacion::findOrFail($a['id_simulacion']);

                return ParticipanteSimulacion::firstOrCreate([
                    'id_simulacion' => $simulacion->getKey(), 'rol' => 'abogado_defensor',
                ], [
                    'id_analisis' => $simulacion->id_analisis, 'id_expediente' => $simulacion->id_expediente,
                    'nombre_mostrado' => 'Defensa de prueba', 'controlado_por' => 'usuario',
                ])->getKey();
            },
            // Para varias intervenciones en la misma simulación, usar sequence().
            'orden' => 1,
            'contenido' => 'Intervención de prueba.',
            'tipo_entrada' => 'texto',
            'fecha_intervencion' => now(),
        ];
    }
}
