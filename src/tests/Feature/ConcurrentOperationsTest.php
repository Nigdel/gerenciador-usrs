<?php

namespace Tests\Feature;

use App\Enums\ApiAbility;
use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Exceptions\OperationInProgressException;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Sprint 1.4 — Una sola operación activa por usuario.
 *
 * Sin este bloqueo, dos peticiones simultáneas sobre la misma persona crean dos
 * operaciones y dos jobs pueden tocar la misma cuenta del subsistema a la vez.
 * Lo que se pierde no es el trabajo —el driver suele responder bien— sino la
 * coherencia: el estado local acaba contando cuentas que el subsistema no tiene,
 * y esa discrepancia hay que corregirla a mano (Sprint 5).
 *
 * La mitad del valor de estos tests está en la excepción: `describir()` es el
 * único sitio donde se comprueba, y todo lo demás —web, API, cola— hereda de ahí.
 */
class ConcurrentOperationsTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private Subsystem $email;

    private UserSubsystemAccount $cuentaEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);

        $this->email = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'api_url' => 'https://email.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $this->cuentaEmail = UserSubsystemAccount::create([
            'gestor_user_id' => $this->usuario->id,
            'subsystem_id' => $this->email->id,
            'credencial_usuario' => 'ana.silva@empresa.test',
            'estado' => SubsystemAccountStatus::Activo,
        ]);
    }

    private function enCurso(?GestorUser $usuario = null): ProvisioningOperation
    {
        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Baja,
            $usuario ?? $this->usuario,
            collect([$this->cuentaEmail]),
        );

        // Se deja 'en curso' a propósito: encolada pero sin ejecutar es como
        // se ve una operación con el worker detrás.
        $operacion->forceFill(['estado' => OperationStatus::EnCurso])->save();

        return $operacion;
    }

    public function test_rechaza_una_segunda_operacion_sobre_el_mismo_usuario(): void
    {
        $activa = $this->enCurso();

        $this->expectException(OperationInProgressException::class);

        app(ProvisioningOperationService::class)->describir(
            OperationType::Reactivacion,
            $this->usuario,
            collect([$this->cuentaEmail]),
        );
    }

    public function test_la_excepcion_lleva_la_operacion_que_la_bloquea(): void
    {
        $activa = $this->enCurso();

        try {
            app(ProvisioningOperationService::class)->describir(
                OperationType::Reactivacion,
                $this->usuario,
                collect([$this->cuentaEmail]),
            );

            $this->fail('Debería haber lanzado OperationInProgressException.');
        } catch (OperationInProgressException $exception) {
            // La API responde 409 con su uuid; sin esto el cliente solo
            // sabría que tiene que esperar, no a qué.
            $this->assertSame($activa->uuid, $exception->operacion?->uuid);
        }
    }

    public function test_no_bloquea_a_otro_usuario(): void
    {
        $this->enCurso();

        $otro = GestorUser::create([
            'nombre_completo' => 'Bruno Costa',
            'cpf' => '98765432100',
            'password_general' => 'Password123!',
            'usuario' => 'bruno.costa',
            'empresa' => 'Empresa Teste',
        ]);

        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Baja,
            $otro,
            collect([$this->cuentaEmail]),
        );

        $this->assertSame(OperationStatus::Pendiente, $operacion->estado);
    }

    public function test_una_operacion_ya_terminada_no_bloquea(): void
    {
        $terminada = $this->enCurso();

        $terminada->forceFill([
            'estado' => OperationStatus::Completada,
            'terminada_at' => now(),
        ])->save();

        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Reactivacion,
            $this->usuario,
            collect([$this->cuentaEmail]),
        );

        $this->assertSame(OperationStatus::Pendiente, $operacion->estado);
    }

    public function test_la_web_redirige_con_el_error(): void
    {
        $this->enCurso();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('gestor-users.show', $this->usuario))
            ->post(route('gestor-users.offboard', $this->usuario), ['motivo_baja' => 'Renuncia'])
            ->assertRedirect(route('gestor-users.show', $this->usuario))
            ->assertSessionHas('error');
    }

    public function test_no_se_crea_la_operacion_rechazada(): void
    {
        $this->enCurso();

        $antes = ProvisioningOperation::query()->count();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('gestor-users.offboard', $this->usuario), ['motivo_baja' => 'Renuncia']);

        // El error se devuelve antes de escribir: si la fila se creara y luego
        // se olvidara quitar, el bloqueo ya no serviría de nada.
        $this->assertSame($antes, ProvisioningOperation::query()->count());
    }

    public function test_la_api_responde_409_con_el_id_de_la_operacion_activa(): void
    {
        $activa = $this->enCurso();

        $token = User::factory()->operador()->create()
            ->createToken('test', [ApiAbility::Suspender->value])
            ->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/usuarios/suspender', [
                'cpf' => '12345678901',
                'subsistemas' => ['email'],
                'motivo_suspension' => 'Licença médica',
            ])
            ->assertStatus(409)
            // Sin esto el 409 solo dice "espera", que es justo lo que el
            // cliente ya sabía: necesita a qué operación seguir.
            ->assertJsonPath('operacion_id', $activa->uuid);
    }

    public function test_el_409_no_deja_una_suspension_colgada(): void
    {
        $this->enCurso();

        $token = User::factory()->operador()->create()
            ->createToken('test', [ApiAbility::Suspender->value])
            ->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/usuarios/suspender', [
                'cpf' => '12345678901',
                'subsistemas' => ['email'],
                'motivo_suspension' => 'Licença médica',
            ])
            ->assertStatus(409);

        // El rechazo llega en describir(), dentro de la transacción y antes de
        // despachar(): si la cuenta quedara con la suspensión ya escrita, el
        // subsistema y la base empezarían a discrepar.
        $this->assertDatabaseHas('user_subsystem_accounts', [
            'id' => $this->cuentaEmail->id,
            'estado' => SubsystemAccountStatus::Activo->value,
        ]);
    }

    public function test_expira_stuck_cierra_la_operacion_sin_actividad(): void
    {
        $atascada = $this->enCurso();

        // En el futuro: la operación es de hace más del umbral.
        $atascada->forceFill(['updated_at' => now()->subHours(2)])->save();

        Artisan::call('operations:expire-stuck');

        $atascada->refresh();

        $this->assertSame(OperationStatus::Fallida, $atascada->estado);
        $this->assertNotNull($atascada->terminada_at);
        $this->assertSame(OperationAccountStatus::Error, $atascada->cuentas()->sole()->estado);
    }

    public function test_expira_stuck_no_toca_una_operacion_reciente(): void
    {
        $reciente = $this->enCurso();

        Artisan::call('operations:expire-stuck');

        $this->assertSame(OperationStatus::EnCurso, $reciente->refresh()->estado);
        $this->assertNull($reciente->terminada_at);
    }

    public function test_expira_stuck_respeta_el_umbral_configurado(): void
    {
        config(['operations.stuck_minutes' => 240]);

        $atascada = $this->enCurso();
        $atascada->forceFill(['updated_at' => now()->subHours(2)])->save();

        // Con cuatro horas de umbral, dos horas sin actividad es normal.
        Artisan::call('operations:expire-stuck');

        $this->assertSame(OperationStatus::EnCurso, $atascada->refresh()->estado);
    }

    public function test_la_operacion_expirada_ya_no_bloquea_al_usuario(): void
    {
        // El motivo por el que existe el comando: sin él el usuario se queda
        // sin poder hacer nada con su ficha para siempre.
        $atascada = $this->enCurso();
        $atascada->forceFill(['updated_at' => now()->subHours(2)])->save();

        Artisan::call('operations:expire-stuck');

        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Reactivacion,
            $this->usuario,
            collect([$this->cuentaEmail]),
        );

        $this->assertSame(OperationStatus::Pendiente, $operacion->estado);
    }
}
