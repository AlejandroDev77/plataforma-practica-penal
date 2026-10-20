<?php

namespace Tests\Unit;

use App\Services\Simulaciones\ValidarEstructuraVersionReglas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidarEstructuraVersionReglasTest extends TestCase
{
    public function test_estructura_valida_no_genera_instrucciones_ni_autorizaciones(): void
    {
        $contenido = self::contenido();
        $resultado = $this->validar($contenido);
        $this->assertTrue($resultado['estructura_valida']);
        $this->assertSame([], $resultado['bloqueos']);
        $this->assertSame(ValidarEstructuraVersionReglas::PERFIL, $resultado['perfil_validacion']);
        $this->assertFalse($resultado['diagnostico_truncado']);
        $this->assertSame(self::contenido(), $contenido);
        $this->assertArrayNotHasKey('instruccion', $resultado);
        $this->assertArrayNotHasKey('execution_authorized', $resultado);
    }

    public static function entradasInvalidas(): array
    {
        return [
            'null' => [null], 'cadena' => ['contenido privado no debe aparecer'], 'vacio' => [[]],
            'formato textual' => [['formato' => '1']], 'formato nuevo' => [['formato' => 2]],
            'tipo textual' => [['tipo' => ['id_tipo_audiencia' => '1']]],
            'coleccion objeto' => [['etapas' => [5 => []]]],
            'coleccion cadena' => [['propuestas' => 'documento privado']],
            'etapas excesivas' => [['etapas' => array_fill(0, 101, [])]],
            'aristas excesivas' => [['transiciones' => array_fill(0, 1001, [])]],
            'propuestas excesivas' => [['propuestas' => array_fill(0, 10001, [])]],
            'referencias excesivas' => [['turnos_catalogo_referencia' => array_fill(0, 10001, [])]],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_rechaza_formato_invalido_sin_error_de_tipos(mixed $cambios): void
    {
        $entrada = is_array($cambios) && $cambios !== [] ? array_replace(self::contenido(), $cambios) : $cambios;
        $resultado = $this->validar($entrada);
        $this->assertFalse($resultado['estructura_valida']);
        $this->assertNotEmpty($resultado['bloqueos']);
        $this->assertStringNotContainsString('privado', json_encode($resultado));
    }

    public static function etapasInvalidas(): array
    {
        return [
            'id textual' => [['id_etapa' => '1']], 'tipo ajeno' => [['id_tipo_audiencia' => 2]],
            'flag textual' => [['activo' => 'true']], 'flag ausente' => [['es_final' => null]],
            'orden negativo' => [['orden' => -1]], 'orden decimal' => [['orden' => 1.1]],
            'nombre vacio' => [['nombre' => ' ']], 'codigo vacio' => [['codigo' => '']],
        ];
    }

    #[DataProvider('etapasInvalidas')]
    public function test_rechaza_etapas_malformadas(array $cambios): void
    {
        $c = self::contenido();
        $c['etapas'][0] = array_replace($c['etapas'][0], $cambios);
        $this->exigirBloqueo('etapa_invalida', $c);
    }

    public function test_exige_inicio_y_cierre_activos_unicos_y_sin_aristas_incompatibles(): void
    {
        foreach (['es_inicial' => 'inicio_activo_no_unico', 'es_final' => 'cierre_activo_no_unico'] as $flag => $codigo) {
            $c = self::contenido();
            foreach ($c['etapas'] as &$etapa) {
                $etapa[$flag] = false;
            }
            unset($etapa);
            $this->exigirBloqueo($codigo, $c);
            foreach ($c['etapas'] as &$etapa) {
                $etapa[$flag] = true;
            }
            unset($etapa);
            $this->exigirBloqueo($codigo, $c);
        }
        $c = self::contenido();
        $c['transiciones'][] = self::transicion(2, 2, 1);
        $this->exigirBloqueo('inicio_con_entrada', $c);
        $this->exigirBloqueo('cierre_con_salida', $c);
        $this->exigirBloqueo('ciclo_no_soportado', $c);
    }

    public function test_etapa_unica_inicial_y_final_es_estructuralmente_posible(): void
    {
        $c = self::contenido();
        $c['etapas'] = [$c['etapas'][0]];
        $c['etapas'][0]['es_final'] = true;
        $c['transiciones'] = [];
        $c['propuestas'] = [$c['propuestas'][0]];
        $this->assertTrue($this->validar($c)['estructura_valida']);
    }

    public function test_ids_ordenes_y_codigos_de_etapa_no_se_duplican(): void
    {
        foreach (['id_etapa', 'orden', 'codigo'] as $campo) {
            $c = self::contenido();
            $c['etapas'][1][$campo] = $c['etapas'][0][$campo];
            $this->exigirBloqueo('etapa_duplicada', $c);
        }
    }

    public static function transicionesInvalidas(): array
    {
        return [
            'tipo ajeno' => [['id_tipo_audiencia' => 2], 'transicion_invalida'],
            'id ausente' => [['id_transicion' => null], 'transicion_invalida'],
            'flag entero' => [['activo' => 1], 'transicion_invalida'],
            'destino inexistente' => [['id_etapa_destino' => 999], 'transicion_sin_extremos_validos'],
            'bucle' => [['id_etapa_destino' => 1], 'transicion_sin_extremos_validos'],
        ];
    }

    #[DataProvider('transicionesInvalidas')]
    public function test_transiciones_no_pueden_cruzar_tipos_ni_extremos(array $cambios, string $codigo): void
    {
        $c = self::contenido();
        $c['transiciones'][0] = array_replace($c['transiciones'][0], $cambios);
        $this->exigirBloqueo($codigo, $c);
    }

    public function test_aristas_duplicadas_y_activa_hacia_etapa_inactiva_se_rechazan(): void
    {
        $c = self::contenido();
        $c['transiciones'][] = self::transicion(2, 1, 2);
        $this->exigirBloqueo('transicion_duplicada', $c);
        $c = self::contenido();
        $c['etapas'][1]['activo'] = false;
        $this->exigirBloqueo('transicion_activa_con_etapa_inactiva', $c);
    }

    public function test_no_se_reparan_aristas_inactivas_o_grafos_desconectados(): void
    {
        $c = self::contenido();
        $c['transiciones'][0]['activo'] = false;
        $this->exigirBloqueo('etapa_sin_salida', $c);
        $this->exigirBloqueo('etapa_inalcanzable_desde_inicio', $c);
        $this->exigirBloqueo('etapa_sin_camino_al_cierre', $c);
    }

    public function test_ramas_validas_no_imponen_destino_ni_orden_lineal(): void
    {
        $c = self::contenido();
        $c['etapas'][] = self::etapa(3);
        $c['etapas'][] = self::etapa(4);
        $c['transiciones'] = [self::transicion(1, 1, 3), self::transicion(2, 1, 4),
            self::transicion(3, 3, 2), self::transicion(4, 4, 2)];
        $c['propuestas'][] = self::propuesta(3, 3);
        $c['propuestas'][] = self::propuesta(4, 4);
        $this->assertTrue($this->validar($c)['estructura_valida']);
        // El orden de visualizacion de una etapa no sustituye las aristas.
        $c['etapas'] = array_reverse($c['etapas']);
        $c['transiciones'] = array_reverse($c['transiciones']);
        $c['propuestas'] = array_reverse($c['propuestas']);
        $this->assertTrue($this->validar($c)['estructura_valida']);
    }

    public function test_ciclo_intermedio_o_desconectado_se_rechaza_sin_recursion_infinita(): void
    {
        $c = self::contenido();
        $c['etapas'][] = self::etapa(3);
        $c['etapas'][] = self::etapa(4);
        $c['transiciones'][] = self::transicion(2, 3, 4);
        $c['transiciones'][] = self::transicion(3, 4, 3);
        $c['propuestas'][] = self::propuesta(3, 3);
        $c['propuestas'][] = self::propuesta(4, 4);
        $this->exigirBloqueo('ciclo_no_soportado', $c);
        $this->exigirBloqueo('etapa_inalcanzable_desde_inicio', $c);
        $c['transiciones'][] = self::transicion(4, 1, 3);
        $c['transiciones'][] = self::transicion(5, 4, 2);
        $this->exigirBloqueo('ciclo_no_soportado', $c);
    }

    public static function propuestasInvalidas(): array
    {
        return [
            'id textual' => [['id_propuesta_turno' => '1'], 'propuesta_invalida'],
            'tipo ajeno' => [['id_tipo_audiencia' => 2], 'propuesta_invalida'],
            'orden cero' => [['orden' => 0], 'propuesta_invalida'],
            'orden excesivo' => [['orden' => 101], 'propuesta_invalida'],
            'orden texto' => [['orden' => '1'], 'propuesta_invalida'],
            'estado aprobado no sustituye revision' => [['estado' => 'aprobado'], 'propuesta_invalida'],
            'texto unicode vacio' => [['descripcion_propuesta' => "\u{00a0}"], 'propuesta_invalida'],
            'descripcion excesiva' => [['descripcion_propuesta' => str_repeat('x', 2001)], 'propuesta_invalida'],
            'autor nulo' => [['id_usuario_creador' => null], 'autoria_propuesta_no_acreditada'],
            'autor texto' => [['id_usuario_creador' => '1'], 'autoria_propuesta_no_acreditada'],
            'rol no soportado' => [['rol' => 'victima'], 'rol_no_soportado'],
            'etapa inexistente' => [['id_etapa' => 999], 'propuesta_sin_etapa_activa'],
        ];
    }

    #[DataProvider('propuestasInvalidas')]
    public function test_turnos_malformados_no_se_filtran(array $cambios, string $codigo): void
    {
        $c = self::contenido();
        $c['propuestas'][0] = array_replace($c['propuestas'][0], $cambios);
        $this->exigirBloqueo($codigo, $c);
    }

    public function test_no_se_inventan_turnos_desde_catalogo_ni_flags(): void
    {
        $c = self::contenido();
        $c['propuestas'] = [];
        $c['turnos_catalogo_referencia'] = [['id_etapa' => 1, 'rol' => 'juez', 'instruccion' => 'No ejecutar.']];
        $c['execution_authorized'] = $c['review_approved'] = true;
        $this->exigirBloqueo('turnos_etapa_ausentes_o_excesivos', $c);
        $c = self::contenido();
        $c['tipo']['activo'] = false;
        $this->exigirBloqueo('tipo_inactivo', $c);
        $c = self::contenido();
        $c['etapas'][1]['activo'] = false;
        $this->exigirBloqueo('propuesta_sin_etapa_activa', $c);
    }

    public function test_propuestas_duplicadas_y_huecos_en_orden(): void
    {
        $c = self::contenido();
        $otra = self::propuesta(3, 1);
        $c['propuestas'][] = $otra;
        $this->exigirBloqueo('propuesta_duplicada', $c);
        $c['propuestas'][2]['orden'] = 7;
        $this->assertTrue($this->validar($c)['estructura_valida']);
        $c['propuestas'][2]['id_propuesta_turno'] = 1;
        $this->exigirBloqueo('propuesta_duplicada', $c);
    }

    public function test_diagnostico_acotado_no_expone_descripciones_ni_resuelve_ciclos(): void
    {
        $c = self::contenido();
        $c['propuestas'] = array_fill(0, 150, 'Contenido privado');
        $r = $this->validar($c);
        $this->assertFalse($r['estructura_valida']);
        $this->assertCount(100, $r['bloqueos']);
        $this->assertTrue($r['diagnostico_truncado']);
        $this->assertStringNotContainsString('privado', json_encode($r));
    }

    private function validar(mixed $contenido): array
    {
        return (new ValidarEstructuraVersionReglas)->ejecutar($contenido, 1);
    }

    private function exigirBloqueo(string $codigo, array $contenido): void
    {
        $r = $this->validar($contenido);
        $this->assertFalse($r['estructura_valida']);
        $this->assertContains($codigo, array_column($r['bloqueos'], 'codigo'));
    }

    public static function contenido(): array
    {
        return ['formato' => 1, 'tipo' => ['id_tipo_audiencia' => 1, 'activo' => true],
            'etapas' => [self::etapa(1, true), self::etapa(2, false, true)],
            'transiciones' => [self::transicion(1, 1, 2)],
            'propuestas' => [self::propuesta(1, 1), self::propuesta(2, 2)], 'turnos_catalogo_referencia' => []];
    }

    private static function etapa(int $id, bool $inicial = false, bool $final = false): array
    {
        return ['id_etapa' => $id, 'id_tipo_audiencia' => 1, 'orden' => $id, 'codigo' => 'sintetica-'.$id,
            'nombre' => 'Etapa sintetica '.$id, 'activo' => true, 'es_inicial' => $inicial, 'es_final' => $final];
    }

    private static function transicion(int $id, int $origen, int $destino): array
    {
        return ['id_transicion' => $id, 'id_tipo_audiencia' => 1, 'id_etapa_origen' => $origen, 'id_etapa_destino' => $destino, 'activo' => true];
    }

    private static function propuesta(int $id, int $etapa): array
    {
        return ['id_propuesta_turno' => $id, 'id_tipo_audiencia' => 1, 'id_etapa' => $etapa, 'id_usuario_creador' => 1,
            'orden' => 1, 'rol' => 'juez', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'Prueba, no instruccion.', 'estado' => 'borrador'];
    }
}
