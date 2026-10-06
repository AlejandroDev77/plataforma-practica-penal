<?php

namespace App\Services\Evaluaciones;

use App\Models\HabilitacionRevisionRubrica;
use App\Models\RegistroRubricaInmutable;
use App\Models\RetiroHabilitacionRubrica;
use App\Models\RetiroRevisionRubrica;
use App\Models\RevisionRubricaEvaluacion;
use App\Models\User;
use App\Models\VersionRubricaEvaluacion;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Comandos internos: no implican activación, permisos de reglas ni ejecución de evaluación. */
final class GestionarRevisionRubricas
{
    public function sellar(User $actor, int $idRubrica, string $motivo, array $autoresDeclarados, mixed $confirmacion): VersionRubricaEvaluacion
    {
        if ($confirmacion !== true || ! array_is_list($autoresDeclarados) || count($autoresDeclarados) < 1
            || count($autoresDeclarados) > 10 || count(array_unique($autoresDeclarados, SORT_REGULAR)) !== count($autoresDeclarados)
            || array_filter($autoresDeclarados, fn ($id) => ! is_int($id) || $id < 1)) {
            throw new DomainException('Declare los autores completos y confirme explícitamente la declaración; no es una acreditación automática.');
        }
        Validator::make(['motivo' => $motivo], ['motivo' => $this->texto(2000)])->validate();

        return $this->guardar(new VersionRubricaEvaluacion, ['id_rubrica' => $idRubrica,
            'id_usuario_preparador' => $actor->getKey(), 'motivo' => $motivo,
            'ids_autores_declarados' => $autoresDeclarados, 'confirmacion_autoria_declarada' => true]);
    }

    public function habilitar(User $actor, int $idRevisor, int $idVersion, array $datos): HabilitacionRevisionRubrica
    {
        $d = $this->validar($datos, ['alcance' => $this->texto(2000), 'referencia_acreditacion' => $this->texto(500),
            'huella_acreditacion' => $this->huella(), 'acreditacion_verificada' => ['required', $this->confirmacion()],
            'fecha_vencimiento' => ['required', ...$this->fecha()]]);
        $v = VersionRubricaEvaluacion::query()->findOrFail($idVersion);

        return $this->guardar(new HabilitacionRevisionRubrica, [...$d, 'id_rubrica' => $v->id_rubrica,
            'id_version_rubrica' => $v->getKey(), 'id_usuario_revisor' => $idRevisor,
            'id_usuario_habilitador' => $actor->getKey(),
            'fecha_vencimiento' => CarbonImmutable::parse($d['fecha_vencimiento'])->utc()]);
    }

    public function revisar(User $actor, int $idVersion, int $idHabilitacion, array $datos): RevisionRubricaEvaluacion
    {
        $d = $this->validar($datos, ['decision' => ['required', Rule::in(['aprobado', 'requiere_cambios'])],
            'observacion' => $this->texto(5000), 'huella_version_revisada' => $this->huella(),
            'id_revision_previa' => ['present', 'nullable', 'integer', 'min:1'],
            'confirmacion_humana' => ['required', $this->confirmacion()], 'autoria_revisada' => ['required', $this->confirmacion()],
            'vigencia_comprobada_en' => ['required', 'date_format:Y-m-d'],
            'fecha_vencimiento' => ['nullable', 'required_if:decision,aprobado', 'prohibited_if:decision,requiere_cambios', ...$this->fecha()],
            'fuentes_verificadas' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'fuentes_verificadas.*' => ['required', 'array:referencia,huella_contenido'],
            'fuentes_verificadas.*.referencia' => $this->texto(500), 'fuentes_verificadas.*.huella_contenido' => $this->huella()]);
        $v = VersionRubricaEvaluacion::query()->findOrFail($idVersion);

        return $this->guardar(new RevisionRubricaEvaluacion, [...$d, 'id_version_rubrica' => $v->getKey(),
            'id_rubrica' => $v->id_rubrica, 'id_habilitacion_rubrica' => $idHabilitacion,
            'id_usuario_revisor' => $actor->getKey(), 'fecha_vencimiento' => isset($d['fecha_vencimiento'])
                ? CarbonImmutable::parse($d['fecha_vencimiento'])->utc() : null]);
    }

    public function retirarHabilitacion(User $actor, int $idHabilitacion, string $motivo): RetiroHabilitacionRubrica
    {
        Validator::make(['motivo' => $motivo], ['motivo' => $this->texto(2000)])->validate();

        return $this->guardar(new RetiroHabilitacionRubrica, ['id_habilitacion_rubrica' => $idHabilitacion,
            'id_usuario_responsable' => $actor->getKey(), 'motivo' => $motivo]);
    }

    public function retirarRevision(User $actor, int $idRevision, string $motivo): RetiroRevisionRubrica
    {
        Validator::make(['motivo' => $motivo], ['motivo' => $this->texto(2000)])->validate();

        return $this->guardar(new RetiroRevisionRubrica, ['id_revision_rubrica' => $idRevision,
            'id_usuario_responsable' => $actor->getKey(), 'motivo' => $motivo]);
    }

    public function estado(int $idVersion): array
    {
        $estado = DB::selectOne('SELECT jurissim_estado_revision_rubrica(?) estado', [$idVersion])->estado;

        return ['id_version_rubrica' => $idVersion, 'estado_revision' => $estado,
            'read_only' => true, 'execution_authorized' => false, 'allowed_actions' => []];
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

            throw new DomainException('No se conservó el registro. Compruebe alcance, independencia, evidencia, vigencia y revisión previa.');
        }
    }

    private function validar(array $datos, array $reglas): array
    {
        $permitidos = array_filter(array_keys($reglas), fn ($k) => ! str_contains($k, '.'));
        if (array_diff(array_keys($datos), $permitidos) !== []) {
            throw ValidationException::withMessages(['solicitud' => 'El comando contiene campos no admitidos.']);
        }

        return Validator::make($datos, $reglas)->validate();
    }

    private function texto(int $max): array
    {
        return ['required', 'string', 'max:'.$max, 'regex:/[^\s\p{Z}]/u'];
    }

    private function huella(): array
    {
        return ['required', 'string', 'regex:/^[0-9a-f]{64}$/'];
    }

    private function fecha(): array
    {
        return ['date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];
    }

    private function confirmacion(): \Closure
    {
        return function ($atributo, $valor, $fallar): void {
            if ($valor !== true) {
                $fallar('Se requiere una confirmación humana con booleano true.');
            }
        };
    }
}
