<?php

namespace App\Services\Simulaciones;

use App\Models\TipoAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SellarVersionReglasAudiencia
{
    public function ejecutar(User $actor, int $idTipoAudiencia, string $motivo, ?array $instrucciones = null, mixed $confirmacionInstrucciones = false, ?string $huellaBorradorEsperada = null): VersionReglasAudiencia
    {
        // Reconsultar los permisos: no confiar en relaciones de roles ya cargadas.
        if (! User::query()->findOrFail($actor->getKey())->hasRole('administrador_plataforma')) {
            throw new AuthorizationException('Solo un administrador de plataforma puede sellar un borrador.');
        }
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 2000) {
            throw new DomainException('Indique un motivo de entre 1 y 2000 caracteres.');
        }

        if ($instrucciones !== null && $confirmacionInstrucciones !== true) {
            throw new DomainException('Debe confirmar expresamente las instrucciones que se conservaran.');
        }

        return DB::transaction(function () use ($actor, $idTipoAudiencia, $motivo, $instrucciones, $huellaBorradorEsperada): VersionReglasAudiencia {
            // Serializa el numero de version por tipo, sin MAX global ni IDs del cliente.
            TipoAudiencia::query()->whereKey($idTipoAudiencia)->lockForUpdate()->firstOrFail();
            // Una unica sentencia captura todos los componentes bajo la misma vista MVCC.
            // No convertir las descripciones propuestas en instrucciones ejecutables.
            $borrador = app(CapturarBorradorReglas::class)->ejecutar($idTipoAudiencia);
            $contenido = $borrador['contenido'];
            if ($huellaBorradorEsperada !== null && ! hash_equals($borrador['huella_borrador'], $huellaBorradorEsperada)) {
                throw new DomainException('El borrador o la ultima version cambiaron. Consulte otra vez antes de sellar.');
            }
            if ($contenido['propuestas'] === [] || $contenido['etapas'] === []) {
                throw new DomainException('Se requieren etapas y al menos una propuesta para sellar una version.');
            }
            if ($borrador['bytes'] > 2_000_000) {
                throw new DomainException('El borrador supera el limite de 2 MB para una version.');
            }
            if ($instrucciones !== null) {
                $contenido['contrato_ejecucion'] = app(ContratoEjecucionReglas::class)->preparar($contenido, $idTipoAudiencia, $instrucciones);
                $bytes = DB::selectOne('SELECT octet_length(?::jsonb::text) AS cantidad', [json_encode($contenido, JSON_THROW_ON_ERROR)])->cantidad;
                if ($bytes > 2_000_000) {
                    throw new DomainException('La copia ejecutable supera el limite de 2 MB.');
                }
            }

            $numero = ((int) VersionReglasAudiencia::query()
                ->where('id_tipo_audiencia', $idTipoAudiencia)->max('numero_version')) + 1;
            $version = new VersionReglasAudiencia;
            $version->id_tipo_audiencia = $idTipoAudiencia;
            $version->id_usuario_autor = $actor->getKey();
            $version->numero_version = $numero;
            $version->motivo = $motivo;
            $version->contenido = $contenido;
            $version->save();

            return $version->refresh();
        });
    }
}
