<?php

namespace Tests\Feature\Api;

use App\Models\EtapaAudiencia;
use App\Models\TipoAudiencia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PropuestaTurnoAudienciaTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')) {
            throw new \RuntimeException('Las pruebas destructivas requieren PostgreSQL jurissim_pruebas, sin DB_URL.');
        }
    }

    public function test_propuestas_solo_estan_disponibles_para_el_administrador_de_plataforma(): void
    {
        $this->getJson('/api/v1/administracion/propuestas-turnos')->assertUnauthorized();

        $administradorInstitucional = User::factory()->create();
        $administradorInstitucional->assignRole(Role::findOrCreate('administrador_institucional', 'web'));
        Sanctum::actingAs($administradorInstitucional);

        $this->getJson('/api/v1/administracion/propuestas-turnos')->assertForbidden();
        $this->postJson('/api/v1/administracion/propuestas-turnos', [])->assertForbidden();
    }

    public function test_guardar_propuesta_crea_solo_un_borrador_y_no_activa_turnos_de_simulacion(): void
    {
        $administrador = $this->administradorPlataforma();
        $etapa = $this->crearEtapa();
        Sanctum::actingAs($administrador);

        $this->postJson('/api/v1/administracion/propuestas-turnos', [
            ...$this->datosPropuesta($etapa),
            'estado' => 'activo',
            'id_usuario_creador' => User::factory()->create()->getKey(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.creador', $administrador->name);

        $this->assertDatabaseHas('propuestas_turnos_audiencia', [
            'id_tipo_audiencia' => $etapa->id_tipo_audiencia,
            'id_etapa' => $etapa->getKey(),
            'orden' => 1,
            'estado' => 'borrador',
            'id_usuario_creador' => $administrador->getKey(),
        ]);
        $this->assertDatabaseMissing('turnos_etapa_audiencia', [
            'id_etapa' => $etapa->getKey(),
            'orden' => 1,
        ]);
    }

    public function test_no_permite_proponer_una_etapa_de_otra_audiencia_ni_repetir_el_orden(): void
    {
        $administrador = $this->administradorPlataforma();
        $etapa = $this->crearEtapa();
        $otraEtapa = $this->crearEtapa('audiencia-distinta');
        Sanctum::actingAs($administrador);

        $this->postJson('/api/v1/administracion/propuestas-turnos', [
            ...$this->datosPropuesta($etapa),
            'id_etapa' => $otraEtapa->getKey(),
        ])->assertUnprocessable()->assertJsonValidationErrors('id_etapa');

        $this->postJson('/api/v1/administracion/propuestas-turnos', $this->datosPropuesta($etapa))->assertCreated();
        $this->postJson('/api/v1/administracion/propuestas-turnos', $this->datosPropuesta($etapa))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('orden');
    }

    public function test_administrador_puede_consultar_editar_y_eliminar_sus_propuestas(): void
    {
        $administrador = $this->administradorPlataforma();
        $etapa = $this->crearEtapa();
        Sanctum::actingAs($administrador);

        $id = $this->postJson('/api/v1/administracion/propuestas-turnos', $this->datosPropuesta($etapa))
            ->assertCreated()
            ->json('data.id');

        $this->getJson('/api/v1/administracion/propuestas-turnos?buscar=apertura')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);

        $this->putJson('/api/v1/administracion/propuestas-turnos/'.$id, [
            ...$this->datosPropuesta($etapa),
            'orden' => 2,
            'acto_propuesto' => 'Apertura revisada',
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.orden', 2)
            ->assertJsonPath('data.acto_propuesto', 'Apertura revisada');

        $this->deleteJson('/api/v1/administracion/propuestas-turnos/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('propuestas_turnos_audiencia', ['id_propuesta_turno' => $id]);
    }

    private function administradorPlataforma(): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));

        return $usuario;
    }

    private function crearEtapa(string $sufijo = 'principal'): EtapaAudiencia
    {
        $tipo = TipoAudiencia::query()->create([
            'nombre' => 'Audiencia de prueba '.$sufijo,
            'codigo' => 'audiencia-'.$sufijo,
            'activo' => true,
            'orden' => 1,
        ]);

        return EtapaAudiencia::query()->create([
            'id_tipo_audiencia' => $tipo->getKey(),
            'codigo' => 'etapa-inicial',
            'nombre' => 'Etapa inicial',
            'orden' => 1,
            'es_inicial' => true,
            'es_final' => false,
            'activo' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function datosPropuesta(EtapaAudiencia $etapa): array
    {
        return [
            'id_tipo_audiencia' => $etapa->id_tipo_audiencia,
            'id_etapa' => $etapa->getKey(),
            'orden' => 1,
            'rol' => 'juez',
            'acto_propuesto' => 'Apertura de audiencia',
            'descripcion_propuesta' => 'Dar inicio a la audiencia y explicar su propósito procesal.',
            'referencia_normativa_propuesta' => null,
            'observaciones' => 'Pendiente de revisión jurídica.',
        ];
    }
}
