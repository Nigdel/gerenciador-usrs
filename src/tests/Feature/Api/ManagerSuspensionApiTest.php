<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Enums\GestorUserStatus;
use App\Enums\SubsystemAccountStatus;
use App\Jobs\ProcessOperationAccount;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagerSuspensionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_programa_la_suspension_del_gestor_aunque_no_tenga_cuentas_activas(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00'));

        $gestor = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);
        $token = User::factory()->operador()->create()
            ->createToken('integracion', [ApiAbility::Suspender->value])
            ->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token);
        Queue::fake();
        Http::preventStrayRequests();

        $response = $this->postJson(route('api.usuarios.suspender-gestor'), [
            'cpf' => '12345678909',
            'motivo_suspension' => 'Licencia médica',
            'inicio_suspension' => '2026-10-31T00:00:00-03:00',
        ])->assertOk()
            ->assertJsonPath('estado', 'pendiente');

        $this->travelTo(Carbon::parse('2026-10-31 03:01:00+00:00'));
        $this->artisan('gestor-users:apply-pending-suspensions')->assertSuccessful();

        $operacion = ProvisioningOperation::query()->where('uuid', $response->json('operacion_id'))->sole();
        $this->assertSame('completada', $operacion->fresh()->estado->value);
        $this->assertSame(GestorUserStatus::Suspendido, $gestor->fresh()->estado);
        Queue::assertNothingPushed();
    }

    public function test_programa_la_suspension_y_reactiva_solo_las_cuentas_que_capturo(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00'));

        $gestor = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);
        $email = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'api_url' => 'https://email.test',
            'api_config' => [],
            'activo' => true,
        ]);
        $activa = UserSubsystemAccount::create([
            'gestor_user_id' => $gestor->id,
            'subsystem_id' => $email->id,
            'credencial_usuario' => 'ana.silva@example.test',
            'external_account_id' => 'email-42',
            'estado' => SubsystemAccountStatus::Activo,
        ]);
        $yaDeshabilitada = UserSubsystemAccount::create([
            'gestor_user_id' => $gestor->id,
            'subsystem_id' => Subsystem::create([
                'nombre' => 'Email alternativo',
                'slug' => 'email-alternativo',
                'api_url' => 'https://email-alternativo.test',
                'api_config' => [],
                'activo' => true,
            ])->id,
            'credencial_usuario' => 'ana.alt@example.test',
            'external_account_id' => 'email-43',
            'estado' => SubsystemAccountStatus::Deshabilitado,
        ]);

        $token = User::factory()->operador()->create()
            ->createToken('integracion', [ApiAbility::Suspender->value])
            ->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token);

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::sequence()
                ->push(['active' => false])
                ->push(['active' => false])
                ->push(['active' => true])
                ->push(['active' => true]),
        ]);

        $suspension = $this->postJson(route('api.usuarios.suspender-gestor'), [
            'cpf' => '123.456.789-09',
            'motivo_suspension' => 'Licencia médica',
            'inicio_suspension' => '2026-10-31T00:00:00-03:00',
            'fin_suspension' => '2026-11-05',
        ])->assertOk()
            ->assertJsonPath('tipo', 'suspension_gestor')
            ->assertJsonPath('estado', 'pendiente');

        $operacion = ProvisioningOperation::query()->where('uuid', $suspension->json('operacion_id'))->sole();
        $this->assertSame(GestorUserStatus::Activo, $gestor->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Activo, $activa->fresh()->estado);
        $this->assertSame(1, $gestor->cuentasSuspendidasPorGestor()->count());
        Queue::assertNothingPushed();

        $this->travelTo(Carbon::parse('2026-10-31 03:01:00+00:00'));
        $this->artisan('gestor-users:apply-pending-suspensions')->assertSuccessful();
        Queue::assertPushed(ProcessOperationAccount::class, 1);
        $this->procesarOperacion($operacion);

        $this->assertSame(GestorUserStatus::Suspendido, $gestor->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Deshabilitado, $activa->fresh()->estado);

        $reactivacion = $this->postJson(route('api.usuarios.habilitar-gestor'), [
            'cpf' => '123.456.789-09',
        ])->assertOk()
            ->assertJsonPath('tipo', 'reactivacion_gestor');

        $operacionReactivacion = ProvisioningOperation::query()
            ->where('uuid', $reactivacion->json('operacion_id'))
            ->sole();
        $this->procesarOperacion($operacionReactivacion);

        $this->assertSame(GestorUserStatus::Activo, $gestor->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Activo, $activa->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Deshabilitado, $yaDeshabilitada->fresh()->estado);
        $this->assertSame(0, $gestor->cuentasSuspendidasPorGestor()->count());
    }
}
