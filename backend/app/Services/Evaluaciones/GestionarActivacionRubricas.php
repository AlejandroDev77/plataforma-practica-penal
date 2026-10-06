<?php

namespace App\Services\Evaluaciones;

use App\Models\ActivacionRubrica;
use App\Models\RegistroRubricaInmutable;
use App\Models\RetiroActivacionRubrica;
use App\Models\RevisionRubricaEvaluacion;
use App\Models\User;
use App\Models\VersionRubricaEvaluacion;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Selección administrativa interna: no activa el evaluador ni cambia el catálogo anterior. */
final class GestionarActivacionRubricas
{
    public function activar(User $actor, int $idVersion, array $datos): ActivacionRubrica
    {
        $campos = ['motivo', 'confirmacion_humana', 'huella_version_esperada', 'id_revision_esperada', 'id_activacion_previa'];
        if (array_diff(array_keys($datos), $campos) !== []) {
            throw ValidationException::withMessages(['solicitud' => 'El comando contiene campos no admitidos.']);
        }
        $entero = fn ($campo, $valor, $fallar) => is_int($valor) && $valor > 0 ?: $fallar('Se requiere un identificador entero nativo.');
        $d = Validator::make($datos, [
            'motivo' => ['required', 'string', 'max:2000', 'regex:/[^\s\p{Z}]/u'],
            'confirmacion_humana' => ['required', fn ($campo, $valor, $fallar) => $valor === true ?: $fallar('Confirme expresamente la activación.')],
            'huella_version_esperada' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'id_revision_esperada' => ['required', $entero],
            'id_activacion_previa' => ['present', 'nullable', $entero],
        ])->validate();
        $v = VersionRubricaEvaluacion::query()->findOrFail($idVersion);
        $r = RevisionRubricaEvaluacion::query()->findOrFail($d['id_revision_esperada']);

        return $this->guardar(new ActivacionRubrica, [
            'id_tipo_audiencia' => $v->id_tipo_audiencia, 'id_rubrica' => $v->id_rubrica,
            'id_version_rubrica' => $v->getKey(), 'id_revision_rubrica' => $r->getKey(),
            'id_habilitacion_rubrica' => $r->id_habilitacion_rubrica, 'id_usuario_responsable' => $actor->getKey(),
            'huella_version' => $d['huella_version_esperada'], 'perfil_validacion' => 'mvp-evaluacion-defensa-v1',
            'motivo' => $d['motivo'], 'confirmacion_humana' => true, 'id_activacion_previa' => $d['id_activacion_previa'],
        ]);
    }

    public function retirar(User $actor, int $idActivacion, string $motivo): RetiroActivacionRubrica
    {
        Validator::make(['motivo' => $motivo], ['motivo' => ['required', 'string', 'max:2000', 'regex:/[^\s\p{Z}]/u']])->validate();

        return $this->guardar(new RetiroActivacionRubrica, ['id_activacion_rubrica' => $idActivacion,
            'id_usuario_responsable' => $actor->getKey(), 'motivo' => $motivo]);
    }

    private function guardar(RegistroRubricaInmutable $registro, array $datos): RegistroRubricaInmutable
    {
        try {
            return DB::transaction(function () use ($registro, $datos): RegistroRubricaInmutable {
                foreach ($datos as $campo => $valor) {
                    $registro->{$campo} = $valor;
                }
                $registro->save();

                return $registro->refresh();
            });
        } catch (QueryException $error) {
            if (! in_array($error->errorInfo[0] ?? null, ['23514', '23503', '23505', '42501', '40001', '40P01', '55P03'], true)) {
                throw $error;
            }
            throw new DomainException('No se conservó la activación. Revise autorización, copia, revisión vigente y lectura previa.');
        }
    }
}
