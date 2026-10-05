<?php

namespace App\Services\Simulaciones;

use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class PrepararAgenteReglado
{
    /** Se usa dentro de la transacción del comando; no llamar al proveedor bajo estos bloqueos. */
    public function ejecutar(User $actor, int $idSimulacion, int $idMovimiento, int $idEtapa,
        int $idTurno, int $orden, array $idsFragmento): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('La preparación exige una transacción.');
        }
        if (! array_is_list($idsFragmento) || count($idsFragmento) > 8
            || count(array_unique($idsFragmento, SORT_REGULAR)) !== count($idsFragmento)
            || array_filter($idsFragmento, fn ($id) => ! is_int($id) || $id <= 0)) {
            throw new DomainException('Seleccione hasta ocho fragmentos verificables, sin repetir.');
        }
        $actor->simulaciones()->whereKey($idSimulacion)
            ->whereHas('expediente', fn ($q) => $q->where('id_usuario', $actor->getKey()))->firstOrFail();
        $fila = DB::selectOne('SELECT jurissim_preparar_agente_reglado(?,?,?,?,?,?,?::jsonb) preparacion',
            [$idSimulacion, $actor->getKey(), $idMovimiento, $idEtapa, $idTurno, $orden, json_encode($idsFragmento, JSON_THROW_ON_ERROR)]);

        return json_decode($fila->preparacion, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Sin SQL, contexto ni texto sensible en errores de dependencia/conflicto. */
    public static function traducir(QueryException $error): never
    {
        if (in_array($error->errorInfo[0] ?? null, ['23514', '23505', '23503', '40001', '40P01', '55P03'], true)) {
            throw new DomainException('No se incorporó el borrador. Revise turno, vigencia y fuentes; consulte de nuevo.');
        }
        throw $error;
    }
}
