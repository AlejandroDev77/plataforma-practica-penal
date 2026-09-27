<?php

namespace Database\Factories;

use App\Models\ArchivoExpediente;
use App\Models\Expediente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArchivoExpediente> */
class ArchivoExpedienteFactory extends Factory
{
    protected $model = ArchivoExpediente::class;

    public function definition(): array
    {
        $nombre = fake()->uuid().'.pdf';

        return [
            'id_expediente' => Expediente::factory(),
            'nombre_original' => 'documento-prueba.pdf',
            'nombre_almacenado' => $nombre,
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$nombre,
            'tamano_bytes' => 1024,
        ];
    }
}
