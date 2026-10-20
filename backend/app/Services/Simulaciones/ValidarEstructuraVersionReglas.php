<?php

namespace App\Services\Simulaciones;

final class ValidarEstructuraVersionReglas
{
    public const PERFIL = 'mvp-defensa-aciclico-v1';

    /** Diagnostico puro: no acredita derecho, compila instrucciones ni autoriza ejecucion. */
    public function ejecutar(mixed $contenido, int $idTipo): array
    {
        $errores = [];
        $truncado = false;
        $anotar = static function (string $codigo, ?int $idEtapa = null, ?int $indice = null) use (&$errores, &$truncado): void {
            if (count($errores) < 100) {
                $errores[] = ['codigo' => $codigo, 'id_etapa' => $idEtapa, 'indice' => $indice];
            } else {
                $truncado = true;
            }
        };
        if ($idTipo < 1 || ! is_array($contenido) || ($contenido['formato'] ?? null) !== 1
            || ! is_array($contenido['tipo'] ?? null)
            || ($contenido['tipo']['id_tipo_audiencia'] ?? null) !== $idTipo) {
            $anotar('formato_o_tipo_invalido');

            return $this->resultado($errores, $truncado);
        }
        foreach (['etapas' => 100, 'transiciones' => 1000, 'propuestas' => 10000, 'turnos_catalogo_referencia' => 10000] as $clave => $limite) {
            if (! is_array($contenido[$clave] ?? null) || ! array_is_list($contenido[$clave]) || count($contenido[$clave]) > $limite) {
                $anotar('coleccion_invalida_o_excesiva');

                return $this->resultado($errores, $truncado);
            }
        }
        if (($contenido['tipo']['activo'] ?? null) !== true) {
            $anotar('tipo_inactivo');
        }
        if ($contenido['etapas'] === []) {
            $anotar('etapas_ausentes');
        }
        $etapas = $ordenes = $codigos = $activas = $iniciales = $finales = [];
        foreach ($contenido['etapas'] as $indice => $etapa) {
            if (! is_array($etapa) || ! $this->id($etapa['id_etapa'] ?? null)
                || ($etapa['id_tipo_audiencia'] ?? null) !== $idTipo
                || ! is_int($etapa['orden'] ?? null) || $etapa['orden'] < 0
                || ! $this->texto($etapa['codigo'] ?? null, 80) || ! $this->texto($etapa['nombre'] ?? null, 255)
                || ! is_bool($etapa['activo'] ?? null) || ! is_bool($etapa['es_inicial'] ?? null) || ! is_bool($etapa['es_final'] ?? null)) {
                $anotar('etapa_invalida', null, $indice);

                continue;
            }
            $id = $etapa['id_etapa'];
            if (isset($etapas[$id]) || isset($ordenes[$etapa['orden']]) || isset($codigos[$etapa['codigo']])) {
                $anotar('etapa_duplicada', $id, $indice);

                continue;
            }
            $etapas[$id] = $etapa;
            $ordenes[$etapa['orden']] = $codigos[$etapa['codigo']] = true;
            if ($etapa['activo']) {
                $activas[$id] = true;
                if ($etapa['es_inicial']) {
                    $iniciales[] = $id;
                }
                if ($etapa['es_final']) {
                    $finales[] = $id;
                }
            }
        }
        if (count($iniciales) !== 1) {
            $anotar('inicio_activo_no_unico');
        }
        if (count($finales) !== 1) {
            $anotar('cierre_activo_no_unico');
        }
        $salidas = $entradas = array_fill_keys(array_keys($activas), []);
        $pares = $idsTransicion = [];
        foreach ($contenido['transiciones'] as $indice => $transicion) {
            if (! is_array($transicion) || ! $this->id($transicion['id_transicion'] ?? null)
                || ($transicion['id_tipo_audiencia'] ?? null) !== $idTipo
                || ! $this->id($transicion['id_etapa_origen'] ?? null) || ! $this->id($transicion['id_etapa_destino'] ?? null)
                || ! is_bool($transicion['activo'] ?? null)) {
                $anotar('transicion_invalida', null, $indice);

                continue;
            }
            $origen = $transicion['id_etapa_origen'];
            $destino = $transicion['id_etapa_destino'];
            $par = $origen.':'.$destino;
            if (isset($pares[$par]) || isset($idsTransicion[$transicion['id_transicion']])) {
                $anotar('transicion_duplicada', $origen, $indice);
            }
            $pares[$par] = $idsTransicion[$transicion['id_transicion']] = true;
            if (! isset($etapas[$origen], $etapas[$destino]) || $origen === $destino) {
                $anotar('transicion_sin_extremos_validos', $origen, $indice);

                continue;
            }
            if (! $transicion['activo']) {
                continue;
            }
            if (! isset($activas[$origen], $activas[$destino])) {
                $anotar('transicion_activa_con_etapa_inactiva', $origen, $indice);

                continue;
            }
            $salidas[$origen][] = $destino;
            $entradas[$destino][] = $origen;
        }
        foreach ($iniciales as $id) {
            if ($entradas[$id] !== []) {
                $anotar('inicio_con_entrada', $id);
            }
        }
        foreach ($finales as $id) {
            if ($salidas[$id] !== []) {
                $anotar('cierre_con_salida', $id);
            }
        }
        foreach ($activas as $id => $_) {
            if ($salidas[$id] === [] && ! in_array($id, $finales, true)) {
                $anotar('etapa_sin_salida', $id);
            }
        }
        if (count($iniciales) === 1 && count($finales) === 1) {
            $desdeInicio = $this->alcanzables($iniciales[0], $salidas);
            $haciaCierre = $this->alcanzables($finales[0], $entradas);
            foreach ($activas as $id => $_) {
                if (! isset($desdeInicio[$id])) {
                    $anotar('etapa_inalcanzable_desde_inicio', $id);
                }
                if (! isset($haciaCierre[$id])) {
                    $anotar('etapa_sin_camino_al_cierre', $id);
                }
            }
        }
        if ($this->tieneCiclo($salidas, $entradas)) {
            $anotar('ciclo_no_soportado');
        }
        $idsPropuesta = $ordenesTurno = [];
        $cantidad = array_fill_keys(array_keys($activas), 0);
        foreach ($contenido['propuestas'] as $indice => $propuesta) {
            if (! is_array($propuesta) || ! $this->id($propuesta['id_propuesta_turno'] ?? null)
                || ($propuesta['id_tipo_audiencia'] ?? null) !== $idTipo || ! $this->id($propuesta['id_etapa'] ?? null)
                || ! is_int($propuesta['orden'] ?? null) || $propuesta['orden'] < 1 || $propuesta['orden'] > 100
                || ($propuesta['estado'] ?? null) !== 'borrador'
                || ! $this->texto($propuesta['acto_propuesto'] ?? null, 160)
                || ! $this->texto($propuesta['descripcion_propuesta'] ?? null, 2000)) {
                $anotar('propuesta_invalida', null, $indice);

                continue;
            }
            $id = $propuesta['id_etapa'];
            $orden = $id.':'.$propuesta['orden'];
            if (isset($idsPropuesta[$propuesta['id_propuesta_turno']]) || isset($ordenesTurno[$orden])) {
                $anotar('propuesta_duplicada', $id, $indice);
            }
            $idsPropuesta[$propuesta['id_propuesta_turno']] = $ordenesTurno[$orden] = true;
            if (! isset($activas[$id])) {
                $anotar('propuesta_sin_etapa_activa', $id, $indice);
            } else {
                $cantidad[$id]++;
            }
            if (! in_array($propuesta['rol'] ?? null, ['juez', 'fiscal', 'abogado_defensor'], true)) {
                $anotar('rol_no_soportado', $id, $indice);
            }
            if (! $this->id($propuesta['id_usuario_creador'] ?? null)) {
                $anotar('autoria_propuesta_no_acreditada', $id, $indice);
            }
        }
        foreach ($cantidad as $id => $numero) {
            if ($numero === 0 || $numero > 100) {
                $anotar('turnos_etapa_ausentes_o_excesivos', $id);
            }
        }

        return $this->resultado($errores, $truncado);
    }

    private function resultado(array $errores, bool $truncado): array
    {
        return ['perfil_validacion' => self::PERFIL, 'estructura_valida' => $errores === [],
            'bloqueos' => $errores, 'diagnostico_truncado' => $truncado];
    }

    private function id(mixed $valor): bool
    {
        return is_int($valor) && $valor > 0;
    }

    private function texto(mixed $valor, int $maximo): bool
    {
        return is_string($valor) && mb_strlen($valor) <= $maximo && preg_match('/[^\s\p{Z}]/u', $valor) === 1;
    }

    private function alcanzables(int $inicio, array $adyacencias): array
    {
        $vistos = [];
        $pendientes = [$inicio];
        while ($pendientes !== []) {
            $id = array_pop($pendientes);
            if (isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;
            foreach ($adyacencias[$id] as $destino) {
                $pendientes[] = $destino;
            }
        }

        return $vistos;
    }

    private function tieneCiclo(array $salidas, array $entradas): bool
    {
        $grados = array_map(count(...), $entradas);
        $pendientes = array_keys(array_filter($grados, fn (int $grado): bool => $grado === 0));
        $visitadas = 0;
        while ($pendientes !== []) {
            $id = array_pop($pendientes);
            $visitadas++;
            foreach ($salidas[$id] as $destino) {
                if (--$grados[$destino] === 0) {
                    $pendientes[] = $destino;
                }
            }
        }

        return $visitadas !== count($salidas);
    }
}
