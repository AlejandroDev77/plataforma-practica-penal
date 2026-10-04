<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Simulaciones\ValidarEstructuraVersionReglas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

final class BorradorInstruccionesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $contenido = $this->resource['contenido'];
        $idTipo = $contenido['tipo']['id_tipo_audiencia'];
        $diagnostico = app(ValidarEstructuraVersionReglas::class)->ejecutar($contenido, $idTipo);
        $vista = ['formato' => $contenido['formato'],
            'tipo' => Arr::only($contenido['tipo'], ['id_tipo_audiencia', 'nombre', 'descripcion', 'activo'])];
        foreach ([
            'etapas' => ['id_etapa', 'nombre', 'orden', 'activo', 'es_inicial', 'es_final'],
            'transiciones' => ['id_etapa_origen', 'id_etapa_destino', 'activo'],
            'propuestas' => ['id_propuesta_turno', 'id_etapa', 'id_usuario_creador', 'orden', 'rol', 'acto_propuesto', 'descripcion_propuesta', 'referencia_normativa_propuesta', 'observaciones'],
        ] as $coleccion => $campos) {
            $vista[$coleccion] = array_map(fn ($fila) => Arr::only($fila, $campos), $contenido[$coleccion]);
        }

        return ['id_tipo_audiencia' => $idTipo, 'huella_borrador' => $this->resource['huella_borrador'],
            'contenido' => $vista, 'estructura_valida' => $diagnostico['estructura_valida'],
            'execution_authorized' => false];
    }
}
