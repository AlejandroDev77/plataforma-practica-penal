<?php

namespace App\Services\Simulaciones;

use App\Models\HabilitacionRevisionJuridica;
use App\Models\RegistroJuridicoInmutable;
use App\Models\RevisionReglasAudiencia;
use App\Models\RevocacionHabilitacionJuridica;
use App\Models\RevocacionRevisionReglas;
use App\Models\TipoAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class GestionarRevisionJuridicaReglas
{
    /** El actor debe proceder de una sesion autenticada, nunca del payload. */
    public function habilitar(User $actor, int $idRevisor, int $idTipo, array $datos): HabilitacionRevisionJuridica
    {
        $this->exigirAdministrador($actor);
        if ($actor->getKey() === $idRevisor) {
            throw new DomainException('No puede habilitarse a si mismo como revisor.');
        }
        $validados = Validator::make($datos, [
            'alcance' => $this->texto(2000), 'referencia_acreditacion' => $this->texto(500),
            'huella_acreditacion' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'acreditacion_verificada' => ['required', $this->confirmacion()],
            'fecha_vencimiento' => $this->fechaFutura(),
        ])->validate();

        return DB::transaction(function () use ($actor, $idRevisor, $idTipo, $validados): HabilitacionRevisionJuridica {
            User::query()->whereKey($idRevisor)->lockForUpdate()->firstOrFail();
            TipoAudiencia::query()->findOrFail($idTipo);
            if ($this->habilitacionVigente($idRevisor, $idTipo)->exists()) {
                throw new DomainException('Ya existe una habilitacion vigente para este ambito.');
            }

            return $this->guardar(new HabilitacionRevisionJuridica, [
                ...$validados, 'id_usuario_revisor' => $idRevisor,
                'id_usuario_habilitador' => $actor->getKey(), 'id_tipo_audiencia' => $idTipo,
                'fecha_vencimiento' => CarbonImmutable::parse($validados['fecha_vencimiento'])->utc(),
            ]);
        });
    }

    public function registrar(User $actor, int $idVersion, array $datos, ?array $precondicion = null, ?string $huellaEjecucionRevisada = null): RevisionReglasAudiencia
    {
        if ($precondicion !== null) {
            $precondicion = Validator::make($precondicion, [
                'huella_version_esperada' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
                'id_ultima_revision_esperada' => ['present', 'nullable', 'integer', 'min:1'],
                'estado_revision_esperado' => ['required', Rule::in([
                    'no_acreditada', 'aprobado', 'requiere_cambios', 'revision_revocada',
                    'habilitacion_revocada', 'habilitacion_vencida', 'revision_vencida',
                ])],
            ])->validate();
        }
        $validados = Validator::make($datos, [
            'decision' => ['required', Rule::in(['aprobado', 'requiere_cambios'])],
            'observacion' => $this->texto(5000),
            'fuentes_verificadas' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'fuentes_verificadas.*' => ['required', 'array:referencia,huella_contenido'],
            'fuentes_verificadas.*.referencia' => $this->texto(500),
            'fuentes_verificadas.*.huella_contenido' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'vigencia_comprobada_en' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/La_Paz')->toDateString()],
            'confirmacion_humana' => ['required', $this->confirmacion()],
            'fecha_vencimiento' => ['nullable', 'required_if:decision,aprobado', 'prohibited_if:decision,requiere_cambios', ...$this->fechaFutura(false)],
        ])->validate();

        return DB::transaction(function () use ($actor, $idVersion, $validados, $precondicion, $huellaEjecucionRevisada): RevisionReglasAudiencia {
            $version = VersionReglasAudiencia::query()->findOrFail($idVersion);
            $tieneContrato = array_key_exists('contrato_ejecucion', $version->contenido);
            if ($tieneContrato) {
                app(ContratoEjecucionReglas::class)->exigir($version->contenido, $version->id_tipo_audiencia);
                if ($huellaEjecucionRevisada === null || ! hash_equals($version->huella_contenido, $huellaEjecucionRevisada)) {
                    throw new DomainException('La revision debe confirmar la copia completa con sus instrucciones exactas.');
                }
            } elseif ($huellaEjecucionRevisada !== null) {
                throw new DomainException('La version no contiene un contrato de ejecucion.');
            }
            $habilitacion = $this->habilitacionVigente($actor->getKey(), $version->id_tipo_audiencia)
                ->lockForUpdate()->first();
            if ($habilitacion === null) {
                throw new AuthorizationException('No tiene una habilitacion vigente para esta audiencia.');
            }
            VersionReglasAudiencia::query()->whereKey($idVersion)->lockForUpdate()->firstOrFail();
            if (RevocacionHabilitacionJuridica::query()->where('id_habilitacion', $habilitacion->getKey())->exists()) {
                throw new AuthorizationException('La habilitacion fue revocada.');
            }
            // Se compara despues de bloquear habilitacion y version, en ese orden.
            // Dos envios con la misma lectura previa no agregan dos decisiones.
            if ($precondicion !== null) {
                $estado = app(ConsultarRevisionJuridicaReglas::class)->ejecutar($idVersion);
                $idEsperado = $precondicion['id_ultima_revision_esperada'];
                if (! hash_equals($version->huella_contenido, $precondicion['huella_version_esperada'])
                    || $estado['review_id'] !== ($idEsperado === null ? null : (int) $idEsperado)
                    || $estado['legal_review_status'] !== $precondicion['estado_revision_esperado']) {
                    throw new DomainException('La version o su revision cambio. Consulte de nuevo antes de decidir.');
                }
            }
            $autores = array_column($version->contenido['propuestas'], 'id_usuario_creador');
            if ($version->id_usuario_autor === $actor->getKey() || in_array($actor->getKey(), $autores, true)
                || in_array(null, $autores, true) || count($autores) !== count($version->contenido['propuestas'])) {
                throw new DomainException('La revision requiere autoria acreditada e independencia del revisor.');
            }
            $vencimiento = isset($validados['fecha_vencimiento'])
                ? CarbonImmutable::parse($validados['fecha_vencimiento'])->utc() : null;
            if ($vencimiento !== null && $vencimiento->greaterThan($habilitacion->fecha_vencimiento)) {
                throw new DomainException('La revision no puede durar mas que su habilitacion.');
            }

            return $this->guardar(new RevisionReglasAudiencia, [
                ...$validados, 'fecha_vencimiento' => $vencimiento,
                ...($tieneContrato ? ['huella_ejecucion_revisada' => $huellaEjecucionRevisada] : []),
                'id_version_reglas' => $idVersion, 'id_tipo_audiencia' => $version->id_tipo_audiencia,
                'id_habilitacion' => $habilitacion->getKey(), 'id_usuario_revisor' => $actor->getKey(),
                'huella_version' => $version->huella_contenido, 'alcance_revisado' => $habilitacion->alcance,
            ]);
        });
    }

    public function revocarHabilitacion(User $actor, int $idHabilitacion, string $motivo): RevocacionHabilitacionJuridica
    {
        Validator::make(['motivo' => $motivo], ['motivo' => $this->texto(2000)])->validate();

        return DB::transaction(function () use ($actor, $idHabilitacion, $motivo): RevocacionHabilitacionJuridica {
            $habilitacion = HabilitacionRevisionJuridica::query()->whereKey($idHabilitacion)->lockForUpdate()->firstOrFail();
            $this->exigirResponsable($actor, $habilitacion->id_usuario_revisor);

            return RevocacionHabilitacionJuridica::query()->where('id_habilitacion', $idHabilitacion)->first()
                ?? $this->guardar(new RevocacionHabilitacionJuridica, [
                    'id_habilitacion' => $idHabilitacion, 'id_usuario_responsable' => $actor->getKey(), 'motivo' => $motivo,
                ]);
        });
    }

    public function revocarRevision(User $actor, int $idRevision, string $motivo): RevocacionRevisionReglas
    {
        Validator::make(['motivo' => $motivo], ['motivo' => $this->texto(2000)])->validate();

        return DB::transaction(function () use ($actor, $idRevision, $motivo): RevocacionRevisionReglas {
            $revision = RevisionReglasAudiencia::query()->findOrFail($idRevision);
            $this->exigirResponsable($actor, $revision->id_usuario_revisor);
            HabilitacionRevisionJuridica::query()->whereKey($revision->id_habilitacion)->lockForUpdate()->firstOrFail();
            VersionReglasAudiencia::query()->whereKey($revision->id_version_reglas)->lockForUpdate()->firstOrFail();

            return RevocacionRevisionReglas::query()->where('id_revision_reglas', $idRevision)->first()
                ?? $this->guardar(new RevocacionRevisionReglas, [
                    'id_revision_reglas' => $idRevision, 'id_usuario_responsable' => $actor->getKey(), 'motivo' => $motivo,
                ]);
        });
    }

    private function habilitacionVigente(int $idRevisor, int $idTipo): Builder
    {
        return HabilitacionRevisionJuridica::query()->where('id_usuario_revisor', $idRevisor)
            ->where('id_tipo_audiencia', $idTipo)->where('fecha_vencimiento', '>', now())
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('revocaciones_habilitacion_juridica')
                ->whereColumn('revocaciones_habilitacion_juridica.id_habilitacion', 'habilitaciones_revision_juridica.id_habilitacion'));
    }

    private function exigirAdministrador(User $actor): void
    {
        if (! User::query()->findOrFail($actor->getKey())->hasRole('administrador_plataforma')) {
            throw new AuthorizationException('Se requiere un administrador de plataforma.');
        }
    }

    private function exigirResponsable(User $actor, int $idRevisor): void
    {
        if ($actor->getKey() !== $idRevisor) {
            $this->exigirAdministrador($actor);
        }
    }

    private function guardar(RegistroJuridicoInmutable $registro, array $valores): RegistroJuridicoInmutable
    {
        foreach ($valores as $campo => $valor) {
            $registro->{$campo} = $valor;
        }
        $registro->save();

        return $registro->refresh();
    }

    private function texto(int $maximo): array
    {
        return ['required', 'string', 'max:'.$maximo, 'regex:/[^\s\p{Z}]/u'];
    }

    private function confirmacion(): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $fallar): void {
            if ($valor !== true) {
                $fallar('Se requiere una confirmacion humana explicita.');
            }
        };
    }

    private function fechaFutura(bool $obligatoria = true): array
    {
        return [...($obligatoria ? ['required'] : []), 'date', 'after:now',
            'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];
    }
}
