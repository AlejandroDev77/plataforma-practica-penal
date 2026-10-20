<?php

namespace App\Services\Simulaciones;

use App\Models\ActivacionReglasAudiencia;
use App\Models\Expediente;
use App\Models\HabilitacionRevisionJuridica;
use App\Models\ParticipanteSimulacion;
use App\Models\Simulacion;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use App\Models\VinculacionReglasPractica;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CrearPracticaConReglas
{
    /** Comando interno de preparacion; no inicia la audiencia ni acepta una practica existente. */
    public function ejecutar(User $actor, int $idExpediente, int $idAnalisis, int $idTipo, int $idActivacion): Simulacion
    {
        return DB::transaction(function () use ($actor, $idExpediente, $idAnalisis, $idTipo, $idActivacion): Simulacion {
            $caso = Expediente::query()->whereKey($idExpediente)->where('id_usuario', $actor->getKey())->lockForUpdate()->firstOrFail();
            $analisis = $caso->analisis()->whereKey($idAnalisis)->lockForUpdate()->firstOrFail();
            if ($analisis->estado !== 'procesado' || $analisis->revisiones()->first()?->decision !== 'aprobado') {
                $this->rechazar('La practica requiere un analisis procesado con revision humana aprobada.');
            }
            DB::selectOne('SELECT id_tipo_audiencia FROM tipos_audiencia WHERE id_tipo_audiencia=? FOR NO KEY UPDATE', [$idTipo]);
            $activacion = ActivacionReglasAudiencia::query()->whereKey($idActivacion)->where('id_tipo_audiencia', $idTipo)->firstOrFail();
            HabilitacionRevisionJuridica::query()->whereKey($activacion->id_habilitacion)->lockForUpdate()->firstOrFail();
            $version = VersionReglasAudiencia::query()->whereKey($activacion->id_version_reglas)->lockForUpdate()->firstOrFail();
            $estado = DB::selectOne('SELECT jurissim_estado_activacion_practica(?) estado', [$idActivacion])->estado;
            if ($estado !== 'vigente') {
                $this->rechazar('La activacion consultada ya no esta vigente. Consulte de nuevo sin sustituirla automaticamente.');
            }
            app(ContratoEjecucionReglas::class)->exigir($version->contenido, $idTipo);
            $configuracion = DB::selectOne('SELECT jurissim_configuracion_practica(contenido) configuracion FROM versiones_reglas_audiencia WHERE id_version_reglas=?',
                [$version->getKey()])->configuracion;
            $simulacion = $actor->simulaciones()->create([
                'id_expediente' => $caso->getKey(), 'id_analisis' => $analisis->getKey(), 'id_tipo_audiencia' => $idTipo,
                'rol_usuario' => 'abogado_defensor', 'estado' => 'preparando',
                'configuracion' => json_decode($configuracion, true, 512, JSON_THROW_ON_ERROR),
            ]);
            $vinculacion = new VinculacionReglasPractica;
            foreach (['id_simulacion' => $simulacion->getKey(), 'id_activacion' => $idActivacion,
                'id_version_reglas' => $version->getKey(), 'id_tipo_audiencia' => $idTipo,
                'id_usuario_responsable' => $actor->getKey(), 'huella_version' => $version->huella_contenido,
                'perfil_validacion' => ContratoEjecucionReglas::PERFIL] as $campo => $valor) {
                $vinculacion->{$campo} = $valor;
            }
            $vinculacion->save();
            $roles = collect($version->contenido['propuestas'])->pluck('rol')->push('abogado_defensor')->unique();
            foreach ($roles as $rol) {
                ParticipanteSimulacion::query()->create([
                    'id_simulacion' => $simulacion->getKey(), 'id_analisis' => $analisis->getKey(), 'id_expediente' => $caso->getKey(),
                    'rol' => $rol, 'controlado_por' => $rol === 'abogado_defensor' ? 'usuario' : 'ia', 'estado' => 'activo',
                    'nombre_mostrado' => $rol === 'abogado_defensor' ? $actor->name : ($rol === 'juez' ? 'Juez simulado' : 'Fiscal simulado'),
                ]);
            }

            return $simulacion->fresh();
        });
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['reglas_audiencia' => $mensaje]);
    }
}
