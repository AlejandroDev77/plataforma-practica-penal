<?php

namespace App\Jobs;

use App\Models\Evaluacion;
use App\Models\Intervencion;
use App\Models\Simulacion;
use App\Services\Analisis\CitasAnalisis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class EvaluarSimulacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $evaluacionId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(): void
    {
        $evaluacion = DB::transaction(function (): ?Evaluacion {
            $registro = Evaluacion::query()->whereKey($this->evaluacionId)->lockForUpdate()->first();
            if ($registro === null || $registro->estado === 'procesado') {
                return null;
            }

            $registro->update(['estado' => 'procesando']);

            return $registro->fresh();
        });

        if ($evaluacion === null) {
            return;
        }

        try {
            $simulacion = Simulacion::query()
                ->with('tipoAudiencia')
                ->whereKey($evaluacion->id_simulacion)
                ->firstOrFail();
            if ($simulacion->estado !== 'finalizada') {
                throw new UnexpectedValueException('La simulación dejó de estar finalizada.');
            }

            $datosPrevios = is_array($evaluacion->datos_evaluacion) ? $evaluacion->datos_evaluacion : [];
            $rubrica = $datosPrevios['rubric'] ?? null;
            $criterios = is_array($rubrica['criteria'] ?? null) ? $rubrica['criteria'] : [];
            if ($criterios === [] || count($criterios) > 20) {
                throw new UnexpectedValueException('La evaluación no conserva una rúbrica válida.');
            }

            $intervenciones = $simulacion->intervenciones()
                ->with(['participante', 'fuentes'])
                ->orderBy('orden')
                ->get();
            $transcripcion = $intervenciones
                ->filter(fn (Intervencion $intervencion): bool => in_array(
                    $intervencion->participante?->rol,
                    ['abogado_defensor', 'juez', 'fiscal'],
                    true,
                ))
                ->map(fn (Intervencion $intervencion): array => [
                    'order' => (int) $intervencion->orden,
                    'role' => $intervencion->participante->rol,
                    'content' => trim((string) $intervencion->contenido),
                    'sources' => $intervencion->participante->rol !== 'abogado_defensor'
                        ? []
                        : $intervencion->fuentes->take(10)
                            ->map(function ($fuente): array {
                                $metadatos = is_array($fuente->metadatos) ? $fuente->metadatos : [];
                                $tipo = $metadatos['tipo_fuente'] ?? null;
                                if (! in_array($tipo, ['expediente', 'juridica'], true)) {
                                    throw new UnexpectedValueException('Una fuente de intervención no tiene un tipo verificable.');
                                }

                                return [
                                    'kind' => $tipo,
                                    'title' => mb_substr(trim((string) ($metadatos['titulo'] ?? 'Fuente validada')) ?: 'Fuente validada', 0, 300),
                                    'locator' => mb_substr(trim((string) ($metadatos['localizador'] ?? 'Referencia')) ?: 'Referencia', 0, 200),
                                    'excerpt' => mb_substr(trim((string) $fuente->fragmento_utilizado), 0, 1_000),
                                ];
                            })
                            ->values()
                            ->all(),
                ])
                ->values()
                ->all();

            if ($transcripcion === [] || count($transcripcion) > 40
                || ! collect($transcripcion)->contains(fn (array $turno): bool => $turno['role'] === 'abogado_defensor')) {
                throw new UnexpectedValueException('La transcripción no cumple los límites de evaluación.');
            }

            $request = [
                'hearing_type' => (string) ($simulacion->tipoAudiencia?->nombre ?? 'Audiencia'),
                'user_role' => $simulacion->rol_usuario,
                'rubric_name' => (string) ($rubrica['name'] ?? ''),
                'rubric_version' => (int) ($rubrica['version'] ?? 0),
                'criteria' => $criterios,
                'transcript' => $transcripcion,
            ];
            $response = $this->requestEvaluation($request);
            $response->throw();

            $proveedor = trim((string) $response->header('X-Jurissim-Evaluation-Provider', ''));
            $modelo = trim((string) $response->header('X-Jurissim-Evaluation-Model', ''));
            if ($proveedor !== 'ollama' || $modelo === '' || mb_strlen($modelo, 'UTF-8') > 150) {
                throw new UnexpectedValueException('El servicio local no confirmó el proveedor y modelo utilizados.');
            }

            $resultado = $response->json('data');
            if (! is_array($resultado)) {
                throw new UnexpectedValueException('El servicio local devolvió una respuesta inválida.');
            }

            $resultado = $this->validarResultado($resultado, $criterios, $transcripcion);
            $this->persistirResultado($evaluacion->getKey(), $datosPrevios, $rubrica, $resultado, $proveedor, $modelo);
        } catch (Throwable $exception) {
            Evaluacion::query()->whereKey($this->evaluacionId)->where('estado', 'procesando')->update([
                'datos_evaluacion' => array_merge(
                    is_array($evaluacion->datos_evaluacion) ? $evaluacion->datos_evaluacion : [],
                    ['error' => 'No se pudo completar la evaluación local.'],
                ),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $evaluacion = Evaluacion::query()->whereKey($this->evaluacionId)->first();
        if ($evaluacion === null || $evaluacion->estado === 'procesado') {
            return;
        }

        $datos = is_array($evaluacion->datos_evaluacion) ? $evaluacion->datos_evaluacion : [];
        $datos['error'] = 'No se pudo completar la evaluación local.';
        $evaluacion->update([
            'estado' => 'error',
            'datos_evaluacion' => $datos,
        ]);
    }

    /** @param array<string, mixed> $resultado
     * @param  list<array<string, mixed>>  $criterios
     * @param  list<array{order: int, role: string, content: string}>  $transcripcion
     * @return array<string, mixed>
     */
    private function validarResultado(array $resultado, array $criterios, array $transcripcion): array
    {
        $reglas = [
            'summary' => ['required', 'string', 'min:1', 'max:1000'],
            'criteria' => ['required', 'array', 'min:1', 'max:20'],
            'criteria.*.criterion_id' => ['required', 'integer', 'min:1', 'distinct'],
            'criteria.*.score' => ['required', 'numeric', 'min:0'],
            'criteria.*.feedback' => ['required', 'string', 'min:1', 'max:1000'],
            'criteria.*.strengths' => ['present', 'array', 'max:4'],
            'criteria.*.errors' => ['present', 'array', 'max:4'],
            'criteria.*.recommendations' => ['present', 'array', 'max:4'],
            'criteria.*.strengths.*.content' => ['required', 'string', 'min:1', 'max:600'],
            'criteria.*.strengths.*.evidence.intervention_order' => ['required', 'integer', 'min:1'],
            'criteria.*.strengths.*.evidence.quote' => ['required', 'string', 'min:1', 'max:500'],
            'criteria.*.errors.*.content' => ['required', 'string', 'min:1', 'max:600'],
            'criteria.*.errors.*.evidence.intervention_order' => ['required', 'integer', 'min:1'],
            'criteria.*.errors.*.evidence.quote' => ['required', 'string', 'min:1', 'max:500'],
            'criteria.*.recommendations.*' => ['required', 'string', 'min:1', 'max:600'],
            'requires_human_review' => ['required', 'accepted'],
        ];
        $validado = Validator::make($resultado, $reglas)->validate();
        if (($resultado['requires_human_review'] ?? null) !== true || ! array_is_list($resultado['criteria'])) {
            throw new UnexpectedValueException('La respuesta local no respetó el contrato de revisión humana.');
        }

        $criteriosPorId = collect($criterios)->keyBy(fn (array $criterio): int => (int) $criterio['id']);
        $turnosDefensa = collect($transcripcion)
            ->where('role', 'abogado_defensor')
            ->keyBy('order');
        $idsDevueltos = collect($validado['criteria'])->pluck('criterion_id')->map(fn ($id): int => (int) $id);
        if ($idsDevueltos->duplicates()->isNotEmpty()
            || $idsDevueltos->sort()->values()->all() !== $criteriosPorId->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()) {
            throw new UnexpectedValueException('La respuesta local no evaluó exactamente los criterios de la rúbrica.');
        }

        foreach ($validado['criteria'] as $criterioResultado) {
            $criterio = $criteriosPorId->get((int) $criterioResultado['criterion_id']);
            if ($criterio === null || (float) $criterioResultado['score'] > (float) $criterio['max_score']) {
                throw new UnexpectedValueException('La respuesta local asignó un puntaje fuera de la rúbrica.');
            }

            foreach ([...$criterioResultado['strengths'], ...$criterioResultado['errors']] as $punto) {
                $turno = $turnosDefensa->get((int) $punto['evidence']['intervention_order']);
                if ($turno === null || ! CitasAnalisis::coincide(
                    $turno['content'],
                    (string) $punto['evidence']['quote'],
                )) {
                    throw new UnexpectedValueException('La respuesta local citó evidencia ajena a la defensa.');
                }
            }
        }

        return $validado;
    }

    /** @param array<string, mixed> $datosPrevios
     * @param  array<string, mixed>  $rubrica
     * @param  array<string, mixed>  $resultado
     */
    private function persistirResultado(
        int $evaluacionId,
        array $datosPrevios,
        array $rubrica,
        array $resultado,
        string $proveedor,
        string $modelo,
    ): void {
        DB::transaction(function () use ($evaluacionId, $datosPrevios, $rubrica, $resultado, $proveedor, $modelo): void {
            $evaluacion = Evaluacion::query()->whereKey($evaluacionId)->lockForUpdate()->firstOrFail();
            if ($evaluacion->estado !== 'procesando') {
                return;
            }

            $criterios = collect($rubrica['criteria'])->keyBy(fn (array $criterio): int => (int) $criterio['id']);
            $pesos = $criterios->sum(fn (array $criterio): float => (float) $criterio['weight']);
            if ($pesos <= 0) {
                throw new UnexpectedValueException('La suma de pesos de la rúbrica debe ser positiva.');
            }

            $puntajePonderado = 0.0;
            $fortalezas = [];
            $errores = [];
            $recomendaciones = [];

            $evaluacion->resultados()->delete();
            foreach ($resultado['criteria'] as $criterioResultado) {
                $criterio = $criterios->get((int) $criterioResultado['criterion_id']);
                $puntajePonderado += ((float) $criterioResultado['score'] / (float) $criterio['max_score'])
                    * (float) $criterio['weight'];
                $evidencias = collect([...$criterioResultado['strengths'], ...$criterioResultado['errors']])
                    ->pluck('evidence.quote')
                    ->implode("\n");

                $evaluacion->resultados()->create([
                    'id_rubrica' => $evaluacion->id_rubrica,
                    'id_criterio' => (int) $criterioResultado['criterion_id'],
                    'puntaje' => round((float) $criterioResultado['score'], 4),
                    'comentario' => $criterioResultado['feedback'],
                    'evidencia' => $evidencias === '' ? null : $evidencias,
                ]);

                foreach ($criterioResultado['strengths'] as $punto) {
                    $fortalezas[] = [
                        'criterion_id' => (int) $criterioResultado['criterion_id'],
                        'criterion_name' => $criterio['name'],
                        'content' => $punto['content'],
                        'evidence' => $punto['evidence'],
                    ];
                }
                foreach ($criterioResultado['errors'] as $punto) {
                    $errores[] = [
                        'criterion_id' => (int) $criterioResultado['criterion_id'],
                        'criterion_name' => $criterio['name'],
                        'content' => $punto['content'],
                        'evidence' => $punto['evidence'],
                    ];
                }
                foreach ($criterioResultado['recommendations'] as $recomendacion) {
                    $recomendaciones[] = [
                        'criterion_id' => (int) $criterioResultado['criterion_id'],
                        'criterion_name' => $criterio['name'],
                        'content' => $recomendacion,
                    ];
                }
            }

            $datos = array_merge($datosPrevios, [
                'summary' => $resultado['summary'],
                'strengths' => $fortalezas,
                'errors' => $errores,
                'recommendations' => $recomendaciones,
                'requires_human_review' => true,
                'provider' => $proveedor,
            ]);

            $evaluacion->update([
                'estado' => 'procesado',
                'puntaje_total' => round(($puntajePonderado / $pesos) * 100, 4),
                'fortalezas' => collect($fortalezas)->pluck('content')->implode("\n"),
                'debilidades' => collect($errores)->pluck('content')->implode("\n"),
                'recomendaciones' => collect($recomendaciones)->pluck('content')->implode("\n"),
                'datos_evaluacion' => $datos,
                'modelo_ia' => $modelo,
                'fecha_evaluacion' => now(),
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function requestEvaluation(array $payload): Response
    {
        $url = (string) config('services.intelligence.url', '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT);
        if ($scheme !== 'http' || $port !== 8100 || ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('La evaluación solo puede enviarse al servicio local.');
        }

        $token = trim((string) config('services.intelligence.token', ''));
        if ($token === '') {
            throw new RuntimeException('Falta configurar el token local del servicio de evaluación.');
        }

        return Http::connectTimeout(5)
            ->timeout(max(1, (int) config('services.intelligence.timeout', 300)))
            ->withoutRedirecting()
            ->withToken($token)
            ->post(rtrim($url, '/').'/api/v1/evaluations/evaluate', $payload);
    }
}
