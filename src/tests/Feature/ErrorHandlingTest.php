<?php

namespace Tests\Feature;

use App\Enums\ApiAbility;
use App\Enums\GestorUserStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use App\Services\SubsystemServiceRegistry;
use App\Services\UserProvisioningService;
use App\Services\UserSuspensionService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Sprint 2.1 — Manejo uniforme de errores.
 *
 * Lo que se comprueba aquí no es que la aplicación no falle —fallar es normal
 * cuando un subsistema externo no está— sino *qué ve quien la llama* cuando
 * eso pasa. La diferencia entre las dos mitades de cada test es deliberada: la
 * que el usuario recibe cambia, y la que no cambia nunca.
 *
 * El caso que más importa es el último: un error que no es de dominio no puede
 * enseñar su mensaje. Ese texto puede llevar el nombre de una columna, la URL
 * de un subsistema o un rastro de pila, y un operador no puede hacer nada con
 * eso —pero quien esté mirando su pantalla sí se lleva la información.
 */
class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private Subsystem $email;

    private UserSubsystemAccount $cuenta;

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

        $this->cuenta = UserSubsystemAccount::create([
            'gestor_user_id' => $this->usuario->id,
            'subsystem_id' => $this->email->id,
            'credencial_usuario' => 'ana.silva@empresa.test',
            'estado' => SubsystemAccountStatus::Activo,
        ]);
    }

    private function token(array $abilities): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test', array_map(fn (ApiAbility $a) => $a->value, $abilities))
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    /** @return array<string, mixed> */
    private function payloadAltaValido(): array
    {
        return [
            'nombre_completo' => 'Bruno Costa',
            'cpf' => '98765432100',
            'password_general' => 'Password123!',
            'subsistemas' => ['email'],
            'empresa' => 'Empresa Teste',
        ];
    }

    /** Excepción de infraestructura: no es de dominio y su texto no debe salir. */
    private function rompiendoElRegistro(): void
    {
        $this->mock(SubsystemServiceRegistry::class, function ($mock): void {
            $mock->shouldReceive('resolveIdentityProvider')
                ->andThrow(new Exception('SQLSTATE[HY000] en la tabla user_subsystem_accounts, host 10.0.0.5'));
        });
    }

    public function test_un_error_de_dominio_muestra_su_mensaje_en_la_web(): void
    {
        // Primero hay que darlo de baja: es lo que hace que la segunda baja
        // sea un error de dominio y no una operación.
        $this->usuario->update(['estado' => GestorUserStatus::Baja]);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('gestor-users.show', $this->usuario))
            ->post(route('gestor-users.offboard', $this->usuario), ['motivo_baja' => 'Renuncia'])
            ->assertSessionHas('error', 'El usuario ya está dado de baja.');
    }

    public function test_el_409_no_se_convierte_en_502(): void
    {
        // El caso que el mapeo nuevo podría pisar sin querer. La operación en
        // curso se lanza desde la ruta real, no desde una sonda: si el 409
        // desapareciera, se rompería el bucle de toda integración.
        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Baja,
            $this->usuario,
            collect([$this->cuenta]),
        );
        $operacion->forceFill(['estado' => OperationStatus::EnCurso])->save();

        $this->token([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', [
                'cpf' => '12345678901',
                'motivo_suspension' => 'Licença médica',
            ])
            ->assertStatus(409)
            ->assertJsonPath('operacion_id', $operacion->uuid);
    }

    public function test_un_error_de_infraestructura_no_muestra_su_mensaje_en_la_web(): void
    {
        Log::spy();

        $this->mock(UserProvisioningService::class, function ($mock): void {
            $mock->shouldReceive('provisionar')
                ->andThrow(new Exception('SQLSTATE[HY000] en la tabla user_subsystem_accounts, host 10.0.0.5'));
        });

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('gestor-users.create'))
            ->post(route('gestor-users.store'), $this->payloadAltaValido())
            ->assertSessionHas('error', 'Ocurrió un error inesperado. Inténtalo de nuevo o avisa a sistemas.');

        // El texto que no se enseñó sí tiene que estar en el log, o no habría
        // forma de averiguar qué pasó.
        Log::shouldHaveReceived('error');
    }

    public function test_un_error_de_infraestructura_no_filtra_datos_en_la_web(): void
    {
        $this->withoutExceptionHandling();

        $this->mock(UserProvisioningService::class, function ($mock): void {
            $mock->shouldReceive('provisionar')
                ->andThrow(new Exception('SQLSTATE[HY000] en la tabla user_subsystem_accounts, host 10.0.0.5'));
        });

        $respuesta = $this->actingAs(User::factory()->admin()->create())
            ->from(route('gestor-users.create'))
            ->post(route('gestor-users.store'), $this->payloadAltaValido());

        // Ni la columna, ni el host, ni el rastro.
        $this->assertStringNotContainsString('SQLSTATE', $respuesta->getContent());
        $this->assertStringNotContainsString('user_subsystem_accounts', $respuesta->getContent());
        $this->assertStringNotContainsString('10.0.0.5', $respuesta->getContent());
    }

    public function test_un_error_de_infraestructura_responde_502_en_la_api(): void
    {
        $this->token([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', [
                'cpf' => '99999999999',
                'motivo_suspension' => 'Licença médica',
            ])
            // El usuario no existe: firstOrFail lanza y no es de dominio, así
            // que se responde 502 —servicio caído— y no un 404. Es lo que
            // decide este mapeo y merece quedar dicho.
            ->assertStatus(502);
    }

    public function test_un_error_de_infraestructura_no_filtra_datos_en_la_api(): void
    {
        $this->mock(UserSuspensionService::class, function ($mock): void {
            $mock->shouldReceive('localizarUsuario')
                ->andThrow(new Exception('Connection refused to https://ldap://10.0.0.5:389 (bind DN=cn=admin)'));
        });

        $respuesta = $this->token([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', [
                'cpf' => '12345678901',
                'motivo_suspension' => 'Licença médica',
            ])
            ->assertStatus(502);

        // La URL del LDAP y el bind DN no salen. Con ellos, cualquier
        // integración que reciba la respuesta sabe cómo hablar con el
        // directorio interno.
        $this->assertStringNotContainsString('10.0.0.5', $respuesta->getContent());
        $this->assertStringNotContainsString('cn=admin', $respuesta->getContent());
        $this->assertStringNotContainsString('Connection refused', $respuesta->getContent());
    }
}
