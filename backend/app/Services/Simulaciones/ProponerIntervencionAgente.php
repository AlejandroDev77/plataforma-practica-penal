<?php

namespace App\Services\Simulaciones;

use App\Models\PaginaExpediente;
use App\Models\ParticipanteSimulacion;
use App\Models\Simulacion;
use App\Modules\Simulations\Application\Contracts\SimulationAgentGateway;
use App\Services\Analisis\CitasAnalisis;
use App\Services\Recuperacion\BuscarFuentesSimulacion;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ProponerIntervencionAgente
{
    public function __construct(
        private ResolverTurnoAudiencia $turnos,
        private BuscarFuentesSimulacion $buscarFuentes,
        private SimulationAgentGateway $agente,
    ) {}

    /** @return array<string, mixed> */
    public function ejecutar(Simulacion $simulacion): array
    {
        $actual = Simulacion::query()
            ->with(['analisis.revisiones', 'etapaActual'])
            ->whereKey($simulacion->getKey())
            ->firstOrFail();

        if ($actual->estado !== 'activa' || $actual->id_etapa_actual === null || $actual->etapaActual?->activo !== true) {
            $this->rechazar('simulacion', 'Solo se puede proponer una intervención en una etapa activa.');
        }

        $turnos = $this->turnos->turnosConfigurados($actual, (int) $actual->id_etapa_actual);
        $intervenciones = $actual->intervenciones()
            ->where('id_etapa', $actual->id_etapa_actual)
            ->with('participante')
            ->orderBy('orden')
            ->get();
        $turno = $turnos[$intervenciones->count()] ?? null;

        if ($turno === null || ! in_array($turno['rol'], ['juez', 'fiscal'], true)) {
            $this->rechazar('turno', 'No hay un turno activo de juez o fiscal para proponer.');
        }

        $instruccion = trim((string) ($turno['instruccion'] ?? ''));

        if ($instruccion === '') {
            $this->rechazar('turno', 'El turno todavía no tiene una instrucción revisada; no se generó contenido.');
        }

        $revision = $actual->analisis?->revisiones->first();

        if ($revision?->decision !== 'aprobado') {
            $this->rechazar('analisis', 'La propuesta requiere un análisis cuya revisión humana vigente siga aprobada.');
        }

        $participante = $actual->participantes()
            ->where('rol', $turno['rol'])
            ->where('controlado_por', 'ia')
            ->where('estado', 'activo')
            ->first();

        if (! $participante instanceof ParticipanteSimulacion) {
            $this->rechazar('turno', 'No existe un participante IA activo para el turno configurado.');
        }

        [$hechos, $fuentes] = $this->contextoVerificado($actual, $instruccion);
        $contexto = [
            'simulation_id' => (string) $actual->getKey(),
            'actor_id' => (string) $participante->getKey(),
            'actor_role' => $turno['rol'],
            'phase' => Str::limit((string) $actual->etapaActual->nombre, 100, ''),
            'turn_instruction' => $instruccion,
            'visible_facts' => $hechos,
            'sources' => $fuentes,
            'transcript' => $intervenciones
                ->take(-12)
                ->map(fn ($intervencion): array => [
                    'role' => $intervencion->participante?->rol ?? 'abogado_defensor',
                    'content' => mb_substr((string) $intervencion->contenido, -300),
                ])
                ->filter(fn (array $mensaje): bool => in_array($mensaje['role'], ['abogado_defensor', 'juez', 'fiscal'], true)
                    && trim($mensaje['content']) !== '')
                ->values()
                ->all(),
        ];

        $resultado = $this->agente->proposeAction($contexto);
        $this->validarRespuesta($resultado, $turno['rol'], $fuentes);

        return [
            'proposal' => [
                'speaker_role' => $resultado['speaker_role'],
                'content' => $resultado['content'],
                'used_source_ids' => $resultado['used_source_ids'],
                'requires_human_review' => true,
            ],
            'sources' => $fuentes,
            'meta' => [
                'provider' => $resultado['_meta']['provider'] ?? null,
                'model' => $resultado['_meta']['model'] ?? null,
            ],
        ];
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function contextoVerificado(Simulacion $simulacion, string $instruccion): array
    {
        $resultado = $simulacion->analisis?->datos_estructurados ?? [];
        $hechosOriginales = array_slice(is_array($resultado['facts'] ?? null) ? $resultado['facts'] : [], 0, 20);
        $idsPagina = collect($hechosOriginales)
            ->flatMap(fn ($hecho) => is_array($hecho) && is_array($hecho['sources'] ?? null)
                ? collect($hecho['sources'])->pluck('page_id')
                : [])
            ->filter(fn ($id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $paginas = PaginaExpediente::query()
            ->with('archivo')
            ->whereIn('id_pagina', $idsPagina)
            ->whereHas('archivo', fn ($query) => $query->where('id_expediente', $simulacion->id_expediente))
            ->get()
            ->keyBy('id_pagina');

        $fuentes = [];
        $idsPorCita = [];
        $hechos = [];

        foreach ($hechosOriginales as $hecho) {
            if (! is_array($hecho)
                || ! is_string($hecho['description'] ?? null)
                || trim($hecho['description']) === ''
                || mb_strlen(trim($hecho['description'])) > 400
                || ! in_array($hecho['kind'] ?? null, ['alegacion', 'declaracion', 'acto_procesal', 'otro'], true)
                || ! in_array($hecho['certainty'] ?? null, ['textual', 'inferido', 'incierto'], true)) {
                continue;
            }

            if (count($hechos) >= 8) {
                break;
            }

            $idsFuente = [];

            foreach (is_array($hecho['sources'] ?? null) ? $hecho['sources'] : [] as $cita) {
                if (! is_array($cita)
                    || filter_var($cita['page_id'] ?? null, FILTER_VALIDATE_INT) === false
                    || ! is_string($cita['excerpt'] ?? null)
                    || trim($cita['excerpt']) === ''
                    || mb_strlen($cita['excerpt']) > 1000) {
                    continue;
                }

                $paginaId = (int) $cita['page_id'];
                $pagina = $paginas->get($paginaId);

                if ($pagina === null
                    || ! $pagina->es_legible
                    || ! is_string($pagina->texto_extraido)
                    || ! CitasAnalisis::coincide($pagina->texto_extraido, $cita['excerpt'])) {
                    continue;
                }

                $claveCita = $paginaId.':'.hash('sha256', trim($cita['excerpt']));

                if (! isset($idsPorCita[$claveCita]) && count($fuentes) < 4) {
                    $idFuente = count($fuentes) + 1;
                    $idsPorCita[$claveCita] = $idFuente;
                    $localizador = trim((string) $pagina->localizador);
                    $fuentes[] = [
                        'id' => $idFuente,
                        'kind' => 'expediente',
                        'title' => Str::limit(trim((string) $pagina->archivo->nombre_original) ?: 'Documento del expediente', 300, ''),
                        'locator' => Str::limit($localizador ?: 'Página '.$pagina->numero_pagina, 200, ''),
                        'excerpt' => mb_substr(trim($cita['excerpt']), 0, 800),
                    ];
                }

                if (isset($idsPorCita[$claveCita])) {
                    $idsFuente[] = $idsPorCita[$claveCita];
                }
            }

            if ($idsFuente !== []) {
                $hechos[] = [
                    'text' => trim($hecho['description']),
                    'kind' => $hecho['kind'],
                    'certainty' => $hecho['certainty'],
                    'attribution' => null,
                    'source_ids' => array_values(array_unique($idsFuente)),
                ];
            }
        }

        $query = trim($instruccion.' '.implode(' ', array_column($hechos, 'text')));

        if ($query !== '') {
            $recuperadas = $this->buscarFuentes->ejecutar($simulacion, mb_substr($query, 0, 1500), 2);

            foreach ([['expediente', 'expediente'], ['juridica', 'juridica']] as [$coleccion, $tipo]) {
                foreach (array_slice($recuperadas[$coleccion]['resultados'] ?? [], 0, 2) as $fuente) {
                    if (count($fuentes) >= 8) {
                        break 2;
                    }

                    $extracto = trim((string) ($fuente['extracto'] ?? ''));

                    if ($extracto === '') {
                        continue;
                    }

                    $metadatos = $fuente['fuente'] ?? [];
                    $titulo = $tipo === 'expediente'
                        ? ($metadatos['archivo'] ?? 'Documento del expediente')
                        : ($metadatos['titulo'] ?? 'Fuente jurídica validada');
                    $localizador = $tipo === 'expediente'
                        ? ($metadatos['localizador'] ?? 'Sin localizador')
                        : (implode(' · ', array_filter([
                            $metadatos['numero_norma'] ?? null,
                            $metadatos['version'] ?? null,
                        ])) ?: 'Fuente jurídica vigente');
                    $titulo = trim((string) $titulo) ?: 'Fuente validada';
                    $localizador = trim((string) $localizador) ?: 'Sin localizador';

                    $fuentes[] = [
                        'id' => count($fuentes) + 1,
                        'kind' => $tipo,
                        'title' => Str::limit((string) $titulo, 300, ''),
                        'locator' => Str::limit((string) $localizador, 200, ''),
                        'excerpt' => mb_substr($extracto, 0, 800),
                    ];
                }
            }
        }

        return [$hechos, $fuentes];
    }

    /** @param list<array<string, mixed>> $fuentes */
    private function validarRespuesta(array $resultado, string $rol, array $fuentes): void
    {
        $idsPermitidos = collect($fuentes)->pluck('id')->all();
        $idsUsados = $resultado['used_source_ids'] ?? null;

        if (($resultado['speaker_role'] ?? null) !== $rol
            || ! is_string($resultado['content'] ?? null)
            || trim($resultado['content']) === ''
            || mb_strlen($resultado['content']) > 3000
            || ($resultado['requires_human_review'] ?? null) !== true
            || ! is_array($idsUsados)
            || ! array_is_list($idsUsados)
            || count($idsUsados) > 8
            || collect($idsUsados)->contains(fn ($id): bool => ! is_int($id) || $id < 1)
            || array_diff($idsUsados, $idsPermitidos) !== []
            || count($idsUsados) !== count(array_unique($idsUsados))) {
            throw new \RuntimeException('El servicio de simulación devolvió una propuesta fuera del contrato esperado.');
        }
    }

    private function rechazar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
