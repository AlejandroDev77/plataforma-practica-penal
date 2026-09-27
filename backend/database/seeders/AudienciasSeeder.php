<?php

namespace Database\Seeders;

use App\Models\EtapaAudiencia;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AudienciasSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ([
                ['medidas_cautelares', 'Medidas cautelares', true],
                ['incidental', 'Audiencia incidental', false],
                ['juicio_oral', 'Juicio oral', false],
            ] as $orden => [$codigo, $nombre, $activo]) {
                TipoAudiencia::firstOrCreate(['codigo' => $codigo], compact('nombre', 'activo', 'orden'));
            }
            $tipo = TipoAudiencia::where('codigo', 'medidas_cautelares')->sole();
            $anterior = null;
            foreach ([
                'apertura' => 'Apertura',
                'identificacion' => 'Identificación',
                'argumentacion_fiscal' => 'Argumentación fiscal',
                'argumentacion_defensa' => 'Argumentación defensa',
                'debate' => 'Debate',
                'resolucion' => 'Resolución',
                'cierre' => 'Cierre',
            ] as $codigo => $nombre) {
                $etapa = EtapaAudiencia::firstOrCreate(
                    ['id_tipo_audiencia' => $tipo->getKey(), 'codigo' => $codigo],
                    ['nombre' => $nombre, 'orden' => $anterior ? $anterior->orden + 1 : 1,
                        'es_inicial' => $codigo === 'apertura', 'es_final' => $codigo === 'cierre', 'activo' => true],
                );
                if ($anterior) {
                    TransicionAudiencia::firstOrCreate([
                        'id_tipo_audiencia' => $tipo->getKey(),
                        'id_etapa_origen' => $anterior->getKey(),
                        'id_etapa_destino' => $etapa->getKey(),
                    ], ['activo' => true]);
                }
                $anterior = $etapa;
            }
        });
    }
}
