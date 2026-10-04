<?php

namespace App\Services\Simulaciones;

use App\Models\User;
use App\Models\VersionReglasAudiencia;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ConsultarPreparacionVersionReglas
{
    public function __construct(
        private ValidarEstructuraVersionReglas $estructura,
        private ConsultarRevisionJuridicaReglas $revision,
    ) {}

    /** Actor autenticado del servidor. Fotografia interna, nunca permiso ni reserva. */
    public function ejecutar(User $actor, int $idVersion): array
    {
        if (! User::query()->findOrFail($actor->getKey())->hasRole('administrador_plataforma')) {
            throw new AuthorizationException('La preparacion de reglas requiere administracion de plataforma.');
        }
        $version = VersionReglasAudiencia::query()->select('versiones_reglas_audiencia.*')
            ->selectRaw("CASE WHEN huella_contenido = encode(sha256(convert_to(contenido::text, 'UTF8')), 'hex') THEN 1 ELSE 0 END AS integridad_valida")
            ->selectRaw('octet_length(contenido::text) AS bytes_contenido')->findOrFail($idVersion);
        $validacion = (int) $version->bytes_contenido <= 2_000_000
            ? $this->estructura->ejecutar($version->contenido, $version->id_tipo_audiencia)
            : ['perfil_validacion' => ValidarEstructuraVersionReglas::PERFIL, 'estructura_valida' => false,
                'bloqueos' => [['codigo' => 'contenido_excesivo', 'id_etapa' => null, 'indice' => null]], 'diagnostico_truncado' => false];
        $estado = $this->revision->ejecutar($idVersion);
        $bloqueos = array_values(array_unique(array_column($validacion['bloqueos'], 'codigo')));
        if ((int) $version->integridad_valida !== 1) {
            $bloqueos[] = 'huella_version_incompatible';
        }
        if (! $estado['review_approved']) {
            $bloqueos[] = 'revision_juridica_no_vigente';
        }

        return [
            'version_id' => $version->getKey(), 'read_only' => true,
            'huella_version' => $version->huella_contenido,
            'validacion_estructural' => $validacion, 'revision_juridica' => $estado,
            'prerrequisitos_preliminares_cumplidos' => $bloqueos === [],
            'bloqueos_preliminares' => $bloqueos,
            'execution_authorized' => false, 'requires_separate_activation' => true,
            'blocking_reasons' => array_values(array_unique([...$bloqueos, 'activacion_separada_pendiente'])),
        ];
    }
}
