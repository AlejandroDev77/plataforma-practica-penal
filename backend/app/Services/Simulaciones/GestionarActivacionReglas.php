<?php

namespace App\Services\Simulaciones;

use App\Models\ActivacionReglasAudiencia;
use App\Models\HabilitacionRevisionJuridica;
use App\Models\RevisionReglasAudiencia;
use App\Models\RevocacionActivacionReglas;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class GestionarActivacionReglas
{
    public function activar(User $actor, int $idVersion, array $datos): ActivacionReglasAudiencia
    {
        $this->exigirAdmin($actor);
        $validados = Validator::make($datos, [
            'motivo' => ['required', 'string', 'max:2000', 'regex:/[^\s\p{Z}]/u'],
            'confirmacion_humana' => ['required', fn ($campo, $valor, $fallar) => $valor === true ?: $fallar('Confirme expresamente la activacion.')],
            'huella_version_esperada' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'id_revision_esperada' => ['required', 'integer', 'min:1'],
            'id_activacion_previa' => ['present', 'nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $idVersion, $validados): ActivacionReglasAudiencia {
            $version = VersionReglasAudiencia::query()->findOrFail($idVersion);
            DB::selectOne('SELECT id_tipo_audiencia FROM tipos_audiencia WHERE id_tipo_audiencia = ? FOR NO KEY UPDATE', [$version->id_tipo_audiencia]);
            $revision = RevisionReglasAudiencia::query()->findOrFail($validados['id_revision_esperada']);
            if ($revision->id_version_reglas !== $idVersion) {
                throw new DomainException('La revision no pertenece a la version solicitada.');
            }
            HabilitacionRevisionJuridica::query()->whereKey($revision->id_habilitacion)->lockForUpdate()->firstOrFail();
            VersionReglasAudiencia::query()->whereKey($idVersion)->lockForUpdate()->firstOrFail();
            $this->exigirAdmin($actor);
            $preparacion = app(ConsultarPreparacionVersionReglas::class)->ejecutar($actor, $idVersion);
            $anterior = ActivacionReglasAudiencia::query()->where('id_tipo_audiencia', $version->id_tipo_audiencia)->orderByDesc('id_activacion')->first();
            if (! $preparacion['prerrequisitos_preliminares_cumplidos']
                || ! hash_equals($version->huella_contenido, $validados['huella_version_esperada'])
                || $preparacion['revision_juridica']['review_id'] !== (int) $validados['id_revision_esperada']
                || $revision->id_version_reglas !== $idVersion
                || $revision->huella_ejecucion_revisada !== $version->huella_contenido
                || $anterior?->getKey() !== ($validados['id_activacion_previa'] === null ? null : (int) $validados['id_activacion_previa'])) {
                throw new DomainException('La lectura previa o sus prerrequisitos cambiaron. Revise antes de activar.');
            }
            app(ContratoEjecucionReglas::class)->exigir($version->contenido, $version->id_tipo_audiencia);
            $activacion = new ActivacionReglasAudiencia;
            foreach (['id_tipo_audiencia' => $version->id_tipo_audiencia, 'id_version_reglas' => $idVersion,
                'id_revision_reglas' => $revision->getKey(), 'id_habilitacion' => $revision->id_habilitacion,
                'id_usuario_responsable' => $actor->getKey(), 'huella_version' => $version->huella_contenido,
                'perfil_validacion' => ContratoEjecucionReglas::PERFIL, 'motivo' => $validados['motivo'],
                'confirmacion_humana' => true, 'id_activacion_previa' => $anterior?->getKey()] as $campo => $valor) {
                $activacion->{$campo} = $valor;
            }
            $activacion->save();

            return $activacion->refresh();
        });
    }

    public function retirar(User $actor, int $idActivacion, string $motivo): RevocacionActivacionReglas
    {
        $this->exigirAdmin($actor);
        Validator::make(['motivo' => $motivo], ['motivo' => ['required', 'string', 'max:2000', 'regex:/[^\s\p{Z}]/u']])->validate();

        return DB::transaction(function () use ($actor, $idActivacion, $motivo): RevocacionActivacionReglas {
            $activacion = ActivacionReglasAudiencia::query()->findOrFail($idActivacion);
            DB::selectOne('SELECT id_tipo_audiencia FROM tipos_audiencia WHERE id_tipo_audiencia = ? FOR NO KEY UPDATE', [$activacion->id_tipo_audiencia]);
            $this->exigirAdmin($actor);
            $existente = RevocacionActivacionReglas::query()->where('id_activacion', $idActivacion)->first();
            if ($existente) {
                return $existente;
            }
            $retiro = new RevocacionActivacionReglas;
            $retiro->id_activacion = $idActivacion;
            $retiro->id_usuario_responsable = $actor->getKey();
            $retiro->motivo = $motivo;
            $retiro->save();

            return $retiro->refresh();
        });
    }

    private function exigirAdmin(User $actor): void
    {
        if (! User::query()->findOrFail($actor->getKey())->hasRole('administrador_plataforma')) {
            throw new AuthorizationException('Se requiere administracion de plataforma para activar o retirar reglas.');
        }
    }
}
