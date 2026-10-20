<?php

namespace App\Services\Simulaciones;

use App\Models\MovimientoPracticaReglada;
use App\Models\Simulacion;
use App\Models\User;
use App\Models\VinculacionReglasPractica;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class MoverPracticaReglada
{
    /** Comando interno: precondicion exacta, sin reintento o eleccion arbitraria. */
    public function ejecutar(User $actor, int $idSimulacion, string $accion, string $estadoEsperado,
        ?int $idEtapaEsperada, ?int $idDestino = null): Simulacion
    {
        if (! in_array($accion, ['iniciar', 'avanzar', 'finalizar'], true)) {
            throw new DomainException('La accion solicitada no esta disponible.');
        }
        // El actor viene de autenticacion; nunca se toma un propietario del cliente.
        $actor->simulaciones()->whereKey($idSimulacion)
            ->whereHas('expediente', fn ($q) => $q->where('id_usuario', $actor->getKey()))->firstOrFail();
        try {
            return DB::transaction(function () use ($actor, $idSimulacion, $accion, $estadoEsperado, $idEtapaEsperada, $idDestino): Simulacion {
                $fila = DB::selectOne('SELECT jurissim_contexto_practica_bloqueado(?,?) contenido', [$idSimulacion, $actor->getKey()]);
                $contenido = json_decode($fila->contenido, true, 512, JSON_THROW_ON_ERROR);
                $s = Simulacion::query()->findOrFail($idSimulacion);
                if ($s->estado !== $estadoEsperado || $s->id_etapa_actual !== $idEtapaEsperada) {
                    throw new DomainException('La practica cambio desde la consulta anterior. Consulte de nuevo.');
                }
                app(ContratoEjecucionReglas::class)->exigir($contenido, $s->id_tipo_audiencia);
                if ($accion === 'iniciar') {
                    if ($idDestino !== null) {
                        throw new DomainException('El inicio se determina desde la version revisada.');
                    }
                    $destino = collect($contenido['etapas'])->where('activo', true)->where('es_inicial', true)->sole()['id_etapa'];
                } elseif ($accion === 'finalizar') {
                    if ($idDestino !== null || $s->id_etapa_actual === null) {
                        throw new DomainException('El cierre no admite un destino distinto.');
                    }
                    $destino = $s->id_etapa_actual;
                } else {
                    $salidas = collect($contenido['transiciones'])->where('activo', true)->where('id_etapa_origen', $s->id_etapa_actual);
                    if ($idDestino === null && $salidas->count() !== 1) {
                        throw new DomainException('Indique un destino permitido cuando exista una bifurcacion.');
                    }
                    $destino = $idDestino ?? $salidas->first()['id_etapa_destino'];
                    if (! $salidas->contains('id_etapa_destino', $destino)) {
                        throw new DomainException('El destino no pertenece a la version revisada.');
                    }
                }
                $vinculo = VinculacionReglasPractica::query()->where('id_simulacion', $idSimulacion)->sole();
                $movimiento = new MovimientoPracticaReglada;
                foreach (['id_simulacion' => $idSimulacion, 'id_vinculacion' => $vinculo->getKey(),
                    'id_usuario_responsable' => $actor->getKey(),
                    'orden' => (int) MovimientoPracticaReglada::query()->where('id_simulacion', $idSimulacion)->max('orden') + 1,
                    'accion' => $accion, 'estado_origen' => $estadoEsperado, 'id_etapa_origen' => $idEtapaEsperada,
                    'estado_destino' => $accion === 'finalizar' ? 'finalizada' : 'activa', 'id_etapa_destino' => $destino] as $campo => $valor) {
                    $movimiento->{$campo} = $valor;
                }
                $movimiento->save();

                return $s->fresh();
            });
        } catch (QueryException $error) {
            if (in_array($error->errorInfo[0] ?? null, ['23514', '23505', '23503', '40001', '40P01', '55P03'], true)) {
                // No exponer SQL, parametros, instrucciones ni detalles internos.
                throw new DomainException('No se aplico el movimiento. Revise vigencia, contexto y turnos antes de intentarlo otra vez.');
            }
            throw $error;
        }
    }
}
