<?php

namespace Tests\Support;

use App\Models\EtapaAudiencia;
use App\Models\Expediente;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\Simulacion;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use App\Services\Simulaciones\CrearPracticaConReglas;
use App\Services\Simulaciones\GestionarActivacionReglas;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\Permission\Models\Role;

trait EscenarioPracticaReglada
{
    private bool $baseVerificada = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comprobarBase();
        $this->baseVerificada = true;
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        RefreshDatabaseState::$migrated = true;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->baseVerificada) {
                $this->comprobarBase();
                Artisan::call('db:wipe', ['--force' => true, '--no-interaction' => true]);
                RefreshDatabaseState::$migrated = false;
            }
        } finally {
            parent::tearDown();
        }
    }

    private function comprobarBase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('SELECT current_database() nombre')->nombre !== 'jurissim_pruebas') {
            throw new RuntimeException('Estas pruebas requieren PostgreSQL jurissim_pruebas, sin DB_URL ni cache.');
        }
        Http::preventStrayRequests();
    }

    protected function escenario(?int $segundosRevision = null, ?string $instruccionLarga = null): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $type = TipoAudiencia::create(['nombre' => 'Practica sintetica', 'codigo' => 'sintetica', 'activo' => true]);
        $stages = $instructions = [];
        foreach ([1, 2, 3] as $orden) {
            $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'codigo' => 'sintetica-'.$orden,
                'nombre' => 'Etapa sintetica', 'orden' => $orden, 'es_inicial' => $orden === 1, 'es_final' => $orden === 3, 'activo' => true]);
            $stages[] = $etapa;
            $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $type->getKey(), 'id_etapa' => $etapa->getKey(),
                'orden' => 1, 'rol' => [1 => 'juez', 2 => 'fiscal', 3 => 'abogado_defensor'][$orden], 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'No es una instruccion.']);
            $p->id_usuario_creador = $admin->getKey();
            $p->save();
            $instructions[] = ['id_propuesta_turno' => $p->getKey(), 'texto' => "  Instruccion sintetica {$orden}.\nSin valor juridico.  "];
        }
        foreach ([[0, 1], [1, 2]] as [$origen, $destino]) {
            TransicionAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'id_etapa_origen' => $stages[$origen]->getKey(), 'id_etapa_destino' => $stages[$destino]->getKey(), 'activo' => true]);
        }
        if ($instruccionLarga !== null) {
            $instructions[0]['texto'] = $instruccionLarga;
        }
        $version = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $type->getKey(), 'Version sintetica.', $instructions, true);
        $grant = app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $reviewer->getKey(), $type->getKey(), [
            'alcance' => 'Ambito sintetico sin valor profesional.', 'referencia_acreditacion' => 'Evidencia sintetica.',
            'huella_acreditacion' => str_repeat('a', 64), 'acreditacion_verificada' => true, 'fecha_vencimiento' => now()->addDays(3)->toIso8601String(),
        ]);
        $case = Expediente::factory()->for($owner, 'usuario')->create();
        $analysis = $case->analisis()->create(['estado' => 'procesado', 'version' => 1]);
        $e = compact('admin', 'owner', 'reviewer', 'type', 'stages', 'instructions', 'version', 'grant', 'case', 'analysis');
        $this->aprobarAnalisis($e);
        $review = $this->revisar($e, $segundosRevision);
        $activation = $this->activar($e, $review);

        return [...$e, ...compact('review', 'activation')];
    }

    protected function revisar(array $e, ?int $segundos = null)
    {
        return app(GestionarRevisionJuridicaReglas::class)->registrar($e['reviewer'], $e['version']->getKey(), [
            'decision' => 'aprobado', 'observacion' => 'Revision sintetica sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => ($segundos === null ? now()->addDays(2) : now()->addSeconds($segundos))->toIso8601String(),
        ], null, $e['version']->huella_contenido);
    }

    protected function activar(array $e, $r, ?int $previa = null)
    {
        return app(GestionarActivacionReglas::class)->activar($e['admin'], $e['version']->getKey(), [
            'motivo' => 'Activacion sintetica.', 'confirmacion_humana' => true, 'huella_version_esperada' => $e['version']->huella_contenido,
            'id_revision_esperada' => $r->getKey(), 'id_activacion_previa' => $previa,
        ]);
    }

    protected function aprobarAnalisis(array $e, string $decision = 'aprobado'): void
    {
        $e['analysis']->revisiones()->create(['id_expediente' => $e['case']->getKey(), 'id_usuario' => $e['owner']->getKey(),
            'decision' => $decision, 'observacion' => 'Revision sintetica.']);
    }

    protected function crear(array $e): Simulacion
    {
        return app(CrearPracticaConReglas::class)->ejecutar($e['owner'], $e['case']->getKey(), $e['analysis']->getKey(), $e['type']->getKey(), $e['activation']->getKey());
    }
}
