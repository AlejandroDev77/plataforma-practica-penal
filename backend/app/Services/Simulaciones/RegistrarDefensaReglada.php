<?php

namespace App\Services\Simulaciones;

use App\Models\Intervencion;
use App\Models\RegistroIntervencionReglada;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class RegistrarDefensaReglada
{
    /** Comando interno. Actor autenticado; contexto esperado obligatorio, sin reintentos. */
    public function ejecutar(User $actor, int $idSimulacion, int $idMovimientoEsperado,
        int $idEtapaEsperada, int $idTurnoEsperado, int $ordenEsperado, string $texto, array $idsFragmento = []): Intervencion
    {
        if (trim(preg_replace('/\s+/u', ' ', $texto) ?? '') === '' || mb_strlen($texto) > 2000
            || ! array_is_list($idsFragmento) || count($idsFragmento) > 10
            || count(array_unique($idsFragmento, SORT_REGULAR)) !== count($idsFragmento)
            || array_filter($idsFragmento, fn ($id) => ! is_int($id) || $id <= 0)) {
            throw new DomainException('Revise el texto (hasta 2000 caracteres) y las fuentes seleccionadas (hasta 10, sin repetir).');
        }
        $actor->simulaciones()->whereKey($idSimulacion)
            ->whereHas('expediente', fn ($q) => $q->where('id_usuario', $actor->getKey()))->firstOrFail();
        try {
            return DB::transaction(function () use ($actor, $idSimulacion, $idMovimientoEsperado,
                $idEtapaEsperada, $idTurnoEsperado, $ordenEsperado, $texto, $idsFragmento): Intervencion {
                $registro = new RegistroIntervencionReglada;
                foreach (['id_simulacion' => $idSimulacion, 'id_usuario_responsable' => $actor->getKey(),
                    'id_movimiento' => $idMovimientoEsperado, 'id_etapa' => $idEtapaEsperada,
                    'id_propuesta_turno' => $idTurnoEsperado, 'orden' => $ordenEsperado,
                    'contenido' => $texto, 'ids_fragmento' => $idsFragmento] as $campo => $valor) {
                    $registro->{$campo} = $valor;
                }
                $registro->save();

                return Intervencion::query()->findOrFail($registro->fresh()->id_intervencion);
            });
        } catch (QueryException $error) {
            if (in_array($error->errorInfo[0] ?? null, ['23514', '23505', '23503', '40001', '40P01', '55P03'], true)) {
                throw new DomainException('No se registro la intervencion. Consulte de nuevo el turno, la vigencia y las fuentes.');
            }
            throw $error;
        }
    }
}
