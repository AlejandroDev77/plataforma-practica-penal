<?php

namespace App\Services\Simulaciones;

use App\Models\ActivacionReglasAudiencia;
use App\Models\RevisionReglasAudiencia;
use App\Models\TipoAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use DomainException;
use Illuminate\Validation\ValidationException;

final readonly class PrepararActivacionAdministrativa
{
    /** Lecturas orientativas, sin reservar vigencia. El comando revalida bajo bloqueo. */
    public function ejecutar(User $actor, int $idVersion): array
    {
        // Esta consulta vuelve a comprobar administracion antes de buscar la version.
        $preparacion = app(ConsultarPreparacionVersionReglas::class)->ejecutar($actor, $idVersion);
        $version = VersionReglasAudiencia::query()->findOrFail($idVersion);
        $actual = app(ConsultarActivacionReglas::class)->ejecutar($actor, $version->id_tipo_audiencia);
        $bloqueos = $preparacion['bloqueos_preliminares'];
        try {
            if (in_array('contenido_excesivo', $bloqueos, true)) {
                throw new DomainException('Copia fuera de limite.');
            }
            app(ContratoEjecucionReglas::class)->exigir($version->contenido, $version->id_tipo_audiencia);
        } catch (DomainException|ValidationException) {
            $bloqueos[] = 'instrucciones_no_compatibles';
        }
        if (! TipoAudiencia::query()->findOrFail($version->id_tipo_audiencia)->activo) {
            $bloqueos[] = 'tipo_inactivo';
        }
        $revision = RevisionReglasAudiencia::query()->find($preparacion['revision_juridica']['review_id']);
        if (! $revision || $revision->huella_ejecucion_revisada !== $version->huella_contenido) {
            $bloqueos[] = 'instrucciones_no_revisadas';
        }
        if ($revision && ActivacionReglasAudiencia::query()->where('id_revision_reglas', $revision->getKey())->exists()) {
            $bloqueos[] = 'revision_ya_utilizada';
        }
        $bloqueos = array_values(array_unique($bloqueos));

        return [
            'id_version_reglas' => $idVersion, 'id_tipo_audiencia' => $version->id_tipo_audiencia,
            'puede_solicitar_activacion' => $bloqueos === [], 'bloqueos' => $bloqueos,
            'precondicion' => [
                'huella_version_esperada' => $version->huella_contenido,
                'id_revision_esperada' => $revision?->getKey(),
                'id_activacion_previa' => $actual['activation_id'],
            ],
            'activacion_actual' => $actual, 'read_only' => true,
            'execution_authorized' => false, 'requires_simulation_binding' => true,
        ];
    }
}
