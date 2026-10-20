<?php

namespace App\Services\Simulaciones;

use DomainException;
use Illuminate\Support\Facades\Validator;

final readonly class ContratoEjecucionReglas
{
    public const PERFIL = 'mvp-defensa-aciclico-ejecucion-v1';

    public function __construct(private ValidarEstructuraVersionReglas $estructura) {}

    /** Instrucciones explicitas, nunca derivadas de una descripcion o del catalogo. */
    public function preparar(array $contenido, int $idTipo, array $instrucciones): array
    {
        if (! $this->estructura->ejecutar($contenido, $idTipo)['estructura_valida']) {
            throw new DomainException('La estructura sellada no es compatible con el perfil de ejecucion.');
        }
        $datos = Validator::make(['instrucciones' => $instrucciones], [
            'instrucciones' => ['required', 'array', 'list', 'min:1', 'max:10000'],
            'instrucciones.*' => ['required', 'array:id_propuesta_turno,texto'],
            'instrucciones.*.id_propuesta_turno' => ['required', 'integer', 'min:1', 'distinct:strict',
                function ($campo, $valor, $fallar): void {
                    if (! is_int($valor)) {
                        $fallar('Se requiere un ID entero estricto.');
                    }
                }],
            'instrucciones.*.texto' => ['required', 'string', 'max:5000', 'regex:/[^\s\p{Z}]/u'],
        ])->validate()['instrucciones'];
        $mapa = array_column($datos, 'texto', 'id_propuesta_turno');
        $ids = array_column($contenido['propuestas'], 'id_propuesta_turno');
        if (count($ids) !== count($mapa) || array_diff($ids, array_keys($mapa)) !== []) {
            throw new DomainException('Cada propuesta de la copia requiere exactamente una instruccion explicita.');
        }

        // Orden del snapshot; rol, etapa y orden proceden de la copia, no del cliente.
        return ['formato' => 1, 'perfil' => self::PERFIL, 'rol_usuario' => 'abogado_defensor',
            'instrucciones' => array_map(fn ($id): array => ['id_propuesta_turno' => $id, 'texto' => $mapa[$id]], $ids)];
    }

    public function exigir(array $contenido, int $idTipo): void
    {
        $contrato = $contenido['contrato_ejecucion'] ?? null;
        if (! is_array($contrato) || ($contrato['formato'] ?? null) !== 1
            || ($contrato['perfil'] ?? null) !== self::PERFIL || ($contrato['rol_usuario'] ?? null) !== 'abogado_defensor'
            || ! is_array($contrato['instrucciones'] ?? null)) {
            throw new DomainException('La copia no tiene un contrato ejecutable compatible.');
        }
        $canonico = $this->preparar($contenido, $idTipo, $contrato['instrucciones']);
        if ($canonico != $contrato) {
            throw new DomainException('El contrato ejecutable no coincide exactamente con la copia.');
        }
    }
}
