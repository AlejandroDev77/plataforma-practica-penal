<?php

namespace App\Services\Simulaciones;

use App\Models\BorradorAgenteReglado;
use App\Models\User;
use App\Modules\Simulations\Application\Contracts\AgenteRegladoGateway;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class ProponerAgenteReglado
{
    public function __construct(private PrepararAgenteReglado $preparar, private AgenteRegladoGateway $agente) {}

    public function ejecutar(User $actor, int $idSimulacion, int $idMovimiento, int $idEtapa,
        int $idTurno, int $orden, array $idsFragmento = [], bool $autorizarExterno = false): BorradorAgenteReglado
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('No genere propuestas dentro de una transacción externa.');
        }
        try {
            $p = DB::transaction(fn () => $this->preparar->ejecutar($actor, $idSimulacion, $idMovimiento, $idEtapa, $idTurno, $orden, $idsFragmento));
            // Fuera de la transacción; la autorización es explícita para este contexto seleccionado.
            $proveedor = $this->agente->proveedor();
            if (! in_array($proveedor['provider'] ?? null, ['ollama', 'openai'], true)
                || ! is_string($proveedor['model'] ?? null) || trim($proveedor['model']) === ''
                || mb_strlen($proveedor['model']) > 150
                || ($proveedor['requires_external_authorization'] ?? null) !== ($proveedor['provider'] === 'openai')
                || ($proveedor['provider'] === 'openai' && ! $autorizarExterno)) {
                throw new DomainException('Autorice el envío del contexto seleccionado antes de usar un proveedor externo.');
            }
            $respuesta = $this->agente->proponer([...$p['contexto'], 'allow_external_processing' => $autorizarExterno]);
            if (($respuesta['_meta']['provider'] ?? null) !== $proveedor['provider']
                || ($respuesta['_meta']['model'] ?? null) !== $proveedor['model']) {
                throw new DomainException('El proveedor cambió durante la solicitud. No se guardó el borrador.');
            }

            return DB::transaction(function () use ($actor, $idSimulacion, $idMovimiento, $idEtapa, $idTurno,
                $orden, $idsFragmento, $autorizarExterno, $p, $respuesta, $proveedor): BorradorAgenteReglado {
                $borrador = new BorradorAgenteReglado;
                foreach (['id_simulacion' => $idSimulacion, 'id_usuario_responsable' => $actor->getKey(),
                    'id_movimiento' => $idMovimiento, 'id_etapa' => $idEtapa, 'id_propuesta_turno' => $idTurno,
                    'orden' => $orden, 'ids_fragmento' => $idsFragmento, 'preparacion' => $p,
                    'respuesta' => array_diff_key($respuesta, ['_meta' => true]), 'proveedor' => $proveedor['provider'],
                    'modelo_ia' => $proveedor['model'], 'autorizar_externo' => $autorizarExterno] as $campo => $valor) {
                    $borrador->{$campo} = $valor;
                }
                $borrador->save();

                return $borrador->fresh();
            });
        } catch (QueryException $error) {
            PrepararAgenteReglado::traducir($error);
        }
    }
}
