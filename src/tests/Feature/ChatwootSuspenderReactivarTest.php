<?php

namespace Tests\Feature;

use App\Enums\SubsystemAccountStatus;
use App\Models\AccountStateLog;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\AccountReactivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chatwoot no tiene estados de suspensión ni de deshabilitado para un agente:
 * desactivar una cuenta es quitarle el acceso (caja de entrada y equipos) y el
 * estado vive en nuestro sistema.
 *
 * Aquí se comprueba el efecto completo desde la web: lo que se le dice a
 * Chatwoot, lo que queda marcado en la cuenta y lo que queda en el historial.
 */
class ChatwootSuspenderReactivarTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(string $estado = 'activo'): UserSubsystemAccount
    {
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        $subsistema = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]],
            'activo' => true,
        ]);

        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => $gestorUser->usuario,
            'external_account_id' => '42',
            'estado' => $estado,
            'configuracion_adicional' => [
                'account_id' => 77,
                'role' => 'agent',
                'team_ids' => [4],
                'inbox_ids' => [16],
            ],
        ]);
    }

    public function test_suspender_le_quita_el_acceso_al_agente_y_deja_rastro_en_el_historial(): void
    {
        $cuenta = $this->cuenta();
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend', $cuenta->user), [
            'motivo_suspension' => 'Fin de contrato',
        ])->assertRedirect(route('gestor-users.show', $cuenta->user));

        $this->assertSame(SubsystemAccountStatus::Suspendido, $cuenta->fresh()->estado);
        $this->assertSame('Fin de contrato', $cuenta->fresh()->motivo_suspension);
        $this->assertNotNull($cuenta->fresh()->inicio_suspension);

        // El agente no se borra: se le quitan los accesos, que es lo que en
        // Chatwoot equivale a no tenerlos.
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/agents/42'));

        // Y la operación queda registrada en el histórico de la cuenta.
        $entrada = AccountStateLog::query()->latest('id')->first();

        $this->assertSame('updated', $entrada->evento);
        $this->assertSame('suspendido', $entrada->cambios['estado']['hasta']);
        $this->assertSame('activo', $entrada->cambios['estado']['desde']);
    }

    public function test_deshabilitar_le_quita_el_acceso_al_agente(): void
    {
        $cuenta = $this->cuenta();
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('subsystems.accounts.action', [$cuenta->subsystem, $cuenta]), [
            'operation' => 'disable',
        ])->assertRedirect(route('subsystems.show', $cuenta->subsystem));

        $this->assertSame(SubsystemAccountStatus::Deshabilitado, $cuenta->fresh()->estado);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v1/accounts/77/inbox_members')
            && $request['user_ids'] === [42]);

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/agents/42'));
    }

    public function test_reactivar_le_devuelve_al_agente_sus_accesos(): void
    {
        $cuenta = $this->cuenta('suspendido');
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents/42' => Http::response(['id' => 42]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('subsystems.accounts.action', [$cuenta->subsystem, $cuenta]), [
            'operation' => 'enable',
        ])->assertRedirect(route('subsystems.show', $cuenta->subsystem));

        $this->assertSame(SubsystemAccountStatus::Activo, $cuenta->fresh()->estado);

        // Se le devuelven la caja de entrada y los equipos que tenía guardados.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/inbox_members')
            && $request['inbox_id'] === 16
            && $request['user_ids'] === [42]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/teams/4/team_members')
            && $request['user_ids'] === [42]);
    }

    public function test_reactivar_crea_el_agente_de_nuevo_si_ya_no_existe_en_chatwoot(): void
    {
        $cuenta = $this->cuenta('suspendido');
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents/42' => Http::response(['error' => 'not found'], 404),
            'https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['id' => 99]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('subsystems.accounts.action', [$cuenta->subsystem, $cuenta]), [
            'operation' => 'enable',
        ])->assertRedirect(route('subsystems.show', $cuenta->subsystem));

        $this->assertSame(SubsystemAccountStatus::Activo, $cuenta->fresh()->estado);

        // El id del agente nuevo se guarda: el anterior ya no vale.
        $this->assertSame('99', $cuenta->fresh()->external_account_id);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents'));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/inbox_members')
            && $request['user_ids'] === [99]);
    }

    public function test_la_reactivacion_de_una_suspension_vencida_devuelve_los_accesos(): void
    {
        $cuenta = $this->cuenta('suspendido');
        $cuenta->update([
            'inicio_suspension' => now()->subDays(10),
            'fin_suspension' => now()->subDay(),
            'motivo_suspension' => 'Licencia',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents/42' => Http::response(['id' => 42]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
        ]);

        $resultado = app(AccountReactivationService::class)->reactivarVencidas();

        $this->assertTrue($resultado->sole()['exito']);
        $this->assertSame(SubsystemAccountStatus::Activo, $cuenta->fresh()->estado);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/inbox_members')
            && $request['user_ids'] === [42]);
    }
}
