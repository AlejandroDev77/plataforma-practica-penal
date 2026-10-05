<?php

namespace App\Services\Simulaciones;

use App\Models\BorradorAgenteReglado;
use App\Models\Intervencion;
use App\Models\RegistroIntervencionReglada;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class ConfirmarAgenteReglado
{
    public function ejecutar(User $actor, int $idSimulacion, int $idBorrador, bool $confirmacionHumana): Intervencion
    {
        if (! $confirmacionHumana) {
            throw new DomainException('Revise el texto y sus fuentes antes de confirmar el borrador.');
        }
        $actor->simulaciones()->whereKey($idSimulacion)
            ->whereHas('expediente', fn ($q) => $q->where('id_usuario', $actor->getKey()))->firstOrFail();
        $b = BorradorAgenteReglado::query()->whereKey($idBorrador)->where('id_simulacion', $idSimulacion)
            ->where('id_usuario_responsable', $actor->getKey())->firstOrFail();
        try {
            return DB::transaction(function () use ($actor, $idSimulacion, $b): Intervencion {
                // Mismo orden de bloqueo que los demás comandos. Repetición no crea ni autoriza otra acción.
                DB::selectOne('SELECT jurissim_contexto_practica_bloqueado(?,?)', [$idSimulacion, $actor->getKey()]);
                $existente = RegistroIntervencionReglada::query()->where('id_borrador', $b->getKey())->first();
                if ($existente !== null) {
                    return Intervencion::query()->findOrFail($existente->id_intervencion);
                }
                $r = new RegistroIntervencionReglada;
                foreach (['id_simulacion' => $idSimulacion, 'id_usuario_responsable' => $actor->getKey(),
                    'id_borrador' => $b->getKey(), 'confirmacion_humana' => true,
                    'id_movimiento' => $b->id_movimiento, 'id_etapa' => $b->id_etapa,
                    'id_propuesta_turno' => $b->id_propuesta_turno, 'orden' => $b->orden] as $campo => $valor) {
                    $r->{$campo} = $valor;
                }
                $r->save();

                return Intervencion::query()->findOrFail($r->fresh()->id_intervencion);
            });
        } catch (QueryException $error) {
            PrepararAgenteReglado::traducir($error);
        }
    }
}
