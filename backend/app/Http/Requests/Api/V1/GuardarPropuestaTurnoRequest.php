<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarPropuestaTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrador_plataforma') ?? false;
    }

    public function rules(): array
    {
        $idTipo = $this->integer('id_tipo_audiencia');
        $idEtapa = $this->integer('id_etapa');
        $reglasOrden = ['required', 'integer', 'min:1', 'max:100'];

        $reglaUnica = Rule::unique('propuestas_turnos_audiencia', 'orden')
            ->where('id_etapa', $idEtapa);

        if ($idPropuesta = $this->route('propuesta')) {
            $reglaUnica->ignore($idPropuesta, 'id_propuesta_turno');
        }

        $reglasOrden[] = $reglaUnica;

        return [
            'id_tipo_audiencia' => [
                'required',
                'integer',
                Rule::exists('tipos_audiencia', 'id_tipo_audiencia')->where('activo', true),
            ],
            'id_etapa' => [
                'required',
                'integer',
                Rule::exists('etapas_audiencia', 'id_etapa')
                    ->where('id_tipo_audiencia', $idTipo)
                    ->where('activo', true),
            ],
            'orden' => $reglasOrden,
            'rol' => ['required', 'string', Rule::in(['abogado_defensor', 'juez', 'fiscal'])],
            'acto_propuesto' => ['required', 'string', 'max:160'],
            'descripcion_propuesta' => ['required', 'string', 'max:2000'],
            'referencia_normativa_propuesta' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_tipo_audiencia.required' => 'Seleccione una audiencia activa.',
            'id_tipo_audiencia.exists' => 'La audiencia seleccionada no está disponible.',
            'id_etapa.required' => 'Seleccione una etapa de la audiencia elegida.',
            'id_etapa.exists' => 'La etapa no corresponde a la audiencia seleccionada o ya no está activa.',
            'orden.required' => 'Indique el orden propuesto para este turno.',
            'orden.unique' => 'Ya existe una propuesta con ese orden para la etapa seleccionada.',
            'rol.required' => 'Seleccione el rol que propone para este turno.',
            'rol.in' => 'Seleccione uno de los roles disponibles para el MVP.',
            'acto_propuesto.required' => 'Describa brevemente el acto que propone.',
            'acto_propuesto.max' => 'El acto propuesto no puede superar :max caracteres.',
            'descripcion_propuesta.required' => 'Explique qué debería realizar el rol en este turno.',
            'descripcion_propuesta.max' => 'La descripción no puede superar :max caracteres.',
            'referencia_normativa_propuesta.max' => 'La referencia propuesta no puede superar :max caracteres.',
            'observaciones.max' => 'Las observaciones no pueden superar :max caracteres.',
        ];
    }
}
