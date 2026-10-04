<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Simulaciones\ConsultarRevisionJuridicaReglas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

final class VersionReglasAudienciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $estado = app(ConsultarRevisionJuridicaReglas::class)->ejecutar($this->getKey());
        $datos = [
            'id' => $this->getKey(), 'id_tipo_audiencia' => $this->id_tipo_audiencia,
            'id_usuario_autor' => $this->id_usuario_autor, 'numero_version' => $this->numero_version,
            'motivo' => $this->motivo, 'huella_contenido' => $this->huella_contenido,
            'fecha_creacion' => $this->fecha_creacion->toIso8601String(), 'revision_juridica' => $estado,
            'precondicion_revision' => [
                'huella_version_esperada' => $this->huella_contenido,
                'id_ultima_revision_esperada' => $estado['review_id'],
                'estado_revision_esperado' => $estado['legal_review_status'],
            ],
        ];
        if ($request->route()?->getActionMethod() !== 'index') {
            $contenido = $this->contenido;
            $datos['contenido'] = ['formato' => $contenido['formato'],
                'tipo' => Arr::only($contenido['tipo'], ['id_tipo_audiencia', 'codigo', 'nombre', 'descripcion', 'activo'])];
            $campos = [
                'etapas' => ['id_etapa', 'id_tipo_audiencia', 'codigo', 'nombre', 'descripcion', 'orden', 'es_inicial', 'es_final', 'activo'],
                'transiciones' => ['id_transicion', 'id_tipo_audiencia', 'id_etapa_origen', 'id_etapa_destino', 'activo'],
                'propuestas' => ['id_propuesta_turno', 'id_tipo_audiencia', 'id_etapa', 'id_usuario_creador', 'orden', 'rol',
                    'acto_propuesto', 'descripcion_propuesta', 'referencia_normativa_propuesta', 'observaciones', 'estado'],
                'turnos_catalogo_referencia' => ['id_turno_etapa', 'id_tipo_audiencia', 'id_etapa', 'orden', 'rol', 'instruccion', 'activo'],
            ];
            foreach ($campos as $coleccion => $permitidos) {
                $datos['contenido'][$coleccion] = array_map(fn ($fila) => Arr::only($fila, $permitidos), $contenido[$coleccion]);
            }
        }

        return $datos;
    }
}
