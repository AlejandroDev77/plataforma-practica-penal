<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
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

    public function test_registration_creates_a_basic_user_without_starting_an_admin_session(): void
    {
        $this->fromSpa()->postJson('/api/v1/register', [
            'name' => '  Ana Abogada  ',
            'email' => ' ANA@example.test ',
            'password' => 'UnaClaveSegura123',
            'password_confirmation' => 'UnaClaveSegura123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ana Abogada')
            ->assertJsonPath('data.email', 'ana@example.test')
            ->assertJsonPath('data.roles', ['estudiante'])
            ->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'ana@example.test')->sole();
        $this->assertTrue(Hash::check('UnaClaveSegura123', $user->password));

        $this->fromSpa()->getJson('/api/v1/me')->assertUnauthorized();

        $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'ana@example.test',
            'password' => 'UnaClaveSegura123',
        ])->assertOk();

        $this->fromSpa()->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_registration_validates_confirmation_and_does_not_create_an_account_on_error(): void
    {
        $this->fromSpa()->postJson('/api/v1/register', [
            'name' => 'Ana Abogada',
            'email' => 'ana@example.test',
            'password' => 'UnaClaveSegura123',
            'password_confirmation' => 'OtraClaveSegura123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_uses_the_same_generic_error_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ana@example.test',
            'password' => 'UnaClaveSegura123',
        ]);

        $existing = $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'ana@example.test',
            'password' => 'incorrecta',
        ])->assertUnprocessable();

        $missing = $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'otra@example.test',
            'password' => 'incorrecta',
        ])->assertUnprocessable();

        $this->assertSame(
            $existing->json('errors.email.0'),
            $missing->json('errors.email.0'),
        );
    }

    public function test_logout_invalidates_the_session_and_current_user_requires_authentication(): void
    {
        $this->fromSpa()->getJson('/api/v1/me')->assertUnauthorized();

        User::factory()->create([
            'email' => 'ana@example.test',
            'password' => 'UnaClaveSegura123',
        ]);

        $this->fromSpa()->postJson('/api/v1/login', [
            'email' => 'ana@example.test',
            'password' => 'UnaClaveSegura123',
        ])->assertOk();

        $this->fromSpa()->postJson('/api/v1/logout')->assertNoContent();
        $this->assertTrue(Auth::guard('web')->guest());
    }

    public function test_password_reset_request_does_not_reveal_whether_an_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ana@example.test']);

        $known = $this->fromSpa()->postJson('/api/v1/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'Si existe una cuenta con ese correo, enviaremos instrucciones para recuperar el acceso.');

        $unknown = $this->fromSpa()->postJson('/api/v1/forgot-password', ['email' => 'otra@example.test'])
            ->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_accepts_a_valid_token_and_updates_the_password(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.test']);
        $token = Password::broker()->createToken($user);

        $this->fromSpa()->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NuevaClaveSegura456',
            'password_confirmation' => 'NuevaClaveSegura456',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Tu contraseña se actualizó. Ya puedes iniciar sesión.');

        $this->assertTrue(Hash::check('NuevaClaveSegura456', $user->fresh()->password));
    }

    public function test_privileged_platform_role_requires_confirmed_console_access(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.test']);
        $prompt = '¿Conceder acceso de administrador de plataforma a ana@example.test?';

        $this->artisan('jurissim:conceder-administracion', ['email' => $user->email])
            ->expectsConfirmation($prompt, 'yes')
            ->expectsOutput('Acceso administrativo concedido. Cierre e inicie sesión para actualizar la sesión.')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->hasRole('administrador_plataforma'));
    }

    private function fromSpa(): self
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);
    }
}
