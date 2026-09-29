<?php

namespace App\Services\Recuperacion;

use App\Models\Simulacion;

final class BuscarFuentesSimulacion
{
    public function __construct(
        private readonly BuscarFragmentosExpediente $fragmentosExpediente,
        private readonly BuscarFragmentosJuridicos $fragmentosJuridicos,
    ) {}

    /**
     * @return array{
     *     expediente: array{resultados: list<array<string, mixed>>, cantidad_fragmentos: int, estado_indice: string},
     *     juridica: array{resultados: list<array<string, mixed>>, cantidad_fuentes: int, cantidad_fragmentos: int}
     * }
     */
    public function ejecutar(Simulacion $simulacion, string $consulta, int $limite = 5): array
    {
        $expediente = $simulacion->expediente;

        return [
            'expediente' => $this->fragmentosExpediente->ejecutar($expediente, $consulta, $limite),
            'juridica' => $this->fragmentosJuridicos->ejecutar($consulta, $limite),
        ];
    }
}
