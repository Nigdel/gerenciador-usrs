<?php

namespace Tests\Feature;

use App\Enums\ApiAbility;
use App\Models\AuditLog;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\AuditService;
use App\Services\UserProvisioningService;
use App\Support\ActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bitácora de acciones administrativas.
 *
 * Dos cosas se comprueban aquí y las dos importan: que la acción quede
 * registrada con quién la hizo y desde dónde, y que al hacerlo no se acabe
 * guardando la credencial que la acción que la llevaba encima. La segunda es la
 * razón de que el filtro viva en AuditService y no en cada punto de llamada.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ActorContext::olvidar();

        parent::tearDown();
    }

    public function test_registra_quien_hizo_la_accion_la_ip_y_el_user_agent(): void
    {
        $operador = User::factory()->admin()->create();
        $this->actingAs($operador);

        $this->post(route('subsystems.store'), [
            'nombre' => 'GLPI',
            'slug' => 'glpi',
        ])->assertRedirect();

        $entrada = AuditLog::where('action', AuditService::SUBSISTEMA_CREADO)->sole();

        $this->assertSame($operador->id, $entrada->user_id);
        $this->assertSame('web', $entrada->origen);
        $this->assertNotNull($entrada->ip_address);
        $this->assertNotNull($entrada->performed_at);
    }

    public function test_el_user_agent_se_guarda_y_se_recorta(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->withServerVariables(['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) PHPUnit'])
            ->post(route('subsystems.store'), ['nombre' => 'GLPI', 'slug' => 'glpi'])
            ->assertRedirect();

        $entrada = AuditLog::where('action', AuditService::SUBSISTEMA_CREADO)->sole();

        $this->assertStringContainsString('PHPUnit', $entrada->user_agent);
    }

    public function test_un_user_agent_absurdamente_largo_no_cabe_entero(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->withServerVariables(['HTTP_USER_AGENT' => str_repeat('a', 5000)])
            ->post(route('subsystems.store'), ['nombre' => 'GLPI', 'slug' => 'glpi'])
            ->assertRedirect();

        $entrada = AuditLog::where('action', AuditService::SUBSISTEMA_CREADO)->sole();

        $this->assertLessThanOrEqual(255, mb_strlen($entrada->user_agent));
    }

    public function test_el_origen_es_api_cuando_la_accion_llega_por_la_api(): void
    {
        // Un token de Sanctum, no una sesión: las rutas de API no pasan por el
        // guard de sesión, así que con actingAs() la petición saldría 401 sin
        // llegar al controlador y no habría nada que auditar.
        $plain = User::factory()->operador()->create()
            ->createToken('test', [ApiAbility::Suspender->value])
            ->plainTextToken;

        $gestorUser = $this->gestorUser();
        $this->conCuentaDe($gestorUser, 'email');

        // La suspensión se ejecuta en cola, y con QUEUE_CONNECTION=sync eso
        // significa dentro de esta misma petición: sin falsear los drivers, el
        // test intenta salir a internet de verdad.
        //
        // La cuenta se crea en un subsistema de correo, no en Adagio, para que
        // el paso sea una sola cosa (registrar el origen) y no dependa de que
        // el login contra el proveedor de identidad funcione.
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/*' => Http::response(null, 404),
            'https://email.test/*' => Http::response(['id' => 'email-42']),
            'https://adagio.test/*' => Http::response(null, 404),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson(route('api.usuarios.suspender'), [
                'cpf' => $gestorUser->cpf,
                'motivo_suspension' => 'Fin de contrato',
            ])->assertOk();

        $this->assertSame('api', AuditLog::where('action', AuditService::USUARIO_SUSPENDIDO)->sole()->origen);
    }

    public function test_el_alta_por_la_api_tambien_se_registra_con_su_origen(): void
    {
        // Sin esto la bitácora solo tendría las altas hechas desde la web, que
        // es la minority: las integraciones entran por la API.
        $this->actingAs(User::factory()->admin()->create());
        $this->fakeAdagio();

        $this->conTokenDe(ApiAbility::Provisionar)
            ->postJson(route('api.usuarios.provisionar'), [
                'nombre_completo' => 'Ana Silva',
                'cpf' => '11144477735',
                'password_general' => 'Password123!',
                'usuario' => 'ana.silva',
                'empresa' => 'Empresa Teste',
            ])->assertCreated();

        $entrada = AuditLog::where('action', AuditService::USUARIO_CREADO)->sole();

        $this->assertSame('api', $entrada->origen);
        $this->assertStringNotContainsString(
            'Password123!',
            $entrada->getRawOriginal('payload'),
        );
    }

    public function test_el_actor_null_no_impide_registrar_la_accion(): void
    {
        // El scheduler no tiene sesión: si log() dependiera de Auth::id() la
        // entrada ni siquiera se escribiría, y el proceso quedaría sin rastro.
        AuditService::class;
        app(AuditService::class)->log(AuditService::SUBSISTEMA_CREADO, [
            'subsistema_id' => 7,
            'nombre' => 'GLPI',
        ]);

        $entrada = AuditLog::where('action', AuditService::SUBSISTEMA_CREADO)->sole();

        $this->assertNull($entrada->user_id);
        $this->assertSame(7, $entrada->payload['subsistema_id']);
    }

    public function test_el_actor_forzado_por_el_scheduler_tambien_se_guarda(): void
    {
        $operador = User::factory()->admin()->create();
        ActorContext::registrar($operador);

        app(AuditService::class)->log(AuditService::SUBSISTEMA_CREADO, ['nombre' => 'GLPI']);

        $this->assertSame($operador->id, AuditLog::where('action', AuditService::SUBSISTEMA_CREADO)->sole()->user_id);
    }

    public function test_la_bitacora_sobrevive_al_borrado_del_operador(): void
    {
        // SQLite no permite cambiar una FK de una tabla existente (por eso la
        // migración se salta ahí), así que en :memory: la restricción sigue
        // siendo CASCADE. Esta regla solo se puede comprobar donde está el
        // dato de verdad.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('La migración de FK no se aplica sobre SQLite.');
        }

        $operador = User::factory()->admin()->create();
        $this->actingAs($operador);

        $this->post(route('subsystems.store'), ['nombre' => 'GLPI', 'slug' => 'glpi'])->assertRedirect();

        $entradaId = AuditLog::where('action', AuditService::SUBSISTEMA_CREADO)->sole()->id;

        $operador->delete();

        $entrada = AuditLog::find($entradaId);

        $this->assertNotNull($entrada, 'La entrada de auditoría no debe desaparecer con el operador.');
        $this->assertNull($entrada->user_id);
        $this->assertSame('GLPI', $entrada->payload['nombre']);
    }

    /*
    |--------------------------------------------------------------------------
    | El filtro de secretos
    |--------------------------------------------------------------------------
    |
    | Esta es la parte que justifica que `limpiar()` esté en el servicio y no
    | repartido en los controladores: cada acción entrega a la bitácora el
    | payload que tenía entre manos, y casi todos esos payloads están a un
    | campo de contener una credencial.
    */

    public function test_el_token_de_un_subsistema_no_se_guarda_ni_a_vista(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('subsystems.store'), [
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
            'api_config' => json_encode([
                'url' => 'https://glpi.test/apirest.php',
                'app_token' => 'tok3n-sup3r-s3cr3t',
                'user_token' => 'otr0-tok3n',
            ]),
        ])->assertRedirect();

        // Se comprueba el contenido crudo de la fila, no el array ya convertido
        // por el cast: si el valor estuviera en la columna, aparecería igual.
        $crudo = AuditLog::where('action', AuditService::SUBSISTEMA_CREADO)->sole()->getRawOriginal('payload');

        $this->assertStringNotContainsString('tok3n-sup3r-s3cr3t', $crudo);
        $this->assertStringNotContainsString('otr0-tok3n', $crudo);

        // Pero sí queda constancia de que se creó, que es lo que se necesita.
        $this->assertSame('GLPI', AuditLog::where('action', AuditService::SUBSISTEMA_CREADO)->sole()->payload['nombre']);
    }

    public function test_actualizar_el_api_config_no_escribe_los_valores_nuevos(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
            'api_config' => ['url' => 'https://glpi.test', 'app_token' => 'viejo'],
            'activo' => true,
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->put(route('subsystems.update', $subsistema), [
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_config' => json_encode(['url' => 'https://glpi.test', 'app_token' => 'tok3n-nuevo']),
        ])->assertRedirect();

        $crudo = AuditLog::where('action', AuditService::SUBSISTEMA_ACTUALIZADO)->sole()->getRawOriginal('payload');

        $this->assertStringNotContainsString('tok3n-nuevo', $crudo);
        $this->assertStringNotContainsString('viejo', $crudo);
    }

    public function test_la_contrasena_general_no_aparece_al_crear_un_usuario(): void
    {
        $adagio = Subsystem::create(['nombre' => 'Adagio', 'slug' => 'adagio', 'activo' => true]);
        $gestorUser = $this->gestorUser();

        // Se simula el servicio en vez de dejarlo hablar con el subsistema: lo
        // que se prueba aquí es qué escribe el controlador en la bitácora, y
        // `datos` lleva la contraseña en claro justo como la llevaría el real.
        $this->mock(UserProvisioningService::class, function ($mock) use ($gestorUser, $adagio): void {
            $mock->shouldReceive('provisionar')
                ->andReturn([
                    'gestor_user' => $gestorUser,
                    'subsistemas' => collect([$adagio]),
                    'datos' => ['usuario' => 'ana', 'password_general' => 'Password123!'],
                    'login_no_verificado' => null,
                ]);
            // Con QUEUE_CONNECTION=sync el job corre dentro de esta misma
            // petición; sin esto el trabajo falla y el controlador redirige
            // antes de auditar.
            $mock->shouldReceive('crearEnSubsistema')
                ->andReturn([
                    'subsistema' => 'adagio',
                    'exito' => true,
                    'mensaje' => 'Cuenta creada',
                    'cuenta' => null,
                ]);
        });

        $this->actingAs(User::factory()->admin()->create());

        // El GestorUser que devuelve el mock ya existe en la base, así que el
        // alta tiene que ir con otro CPF y otro login: si coincidieran, el
        // UNIQUE los rechazaría antes de llegar a la auditoría.
        $this->post(route('gestor-users.store'), [
            ...$this->altaValida(),
            'cpf' => '11144477735',
            'usuario' => 'ana.nueva',
            'subsistemas' => ['adagio'],
        ])->assertRedirect();

        $crudo = AuditLog::where('action', AuditService::USUARIO_CREADO)->sole()->getRawOriginal('payload');

        $this->assertStringNotContainsString('Password123!', $crudo);
    }

    public function test_la_contrasena_general_no_aparece_al_actualizar_un_usuario(): void
    {
        $gestorUser = $this->gestorUser();

        $this->actingAs(User::factory()->admin()->create());

        $this->put(route('gestor-users.update', $gestorUser), [
            'nombre_completo' => 'Ana Silva Editada',
            'cpf' => '12345678909',
            'password_general' => 'OtraClave456!',
            'empresa' => 'klios',
        ])->assertRedirect();

        $entrada = AuditLog::where('action', AuditService::USUARIO_ACTUALIZADO)->sole();
        $crudo = $entrada->getRawOriginal('payload');

        $this->assertStringNotContainsString('OtraClave456!', $crudo);

        // Ni el hash de la contraseña nueva, que es tan reutilizable como ella.
        $hash = $gestorUser->fresh()->password_general;
        $this->assertStringNotContainsString($hash, $crudo);

        // El filtro de secretos del servicio tiene prioridad sobre el diff:
        // aunque el diff diga 'cambiada', la clave password_general se redacta
        // igual. Lo que queda es la marca, nunca el valor.
        $this->assertSame('[oculto]', $entrada->payload['cambios']['password_general']);
    }

    public function test_la_contrasena_general_no_aparece_al_restablecer_contrasenas(): void
    {
        $gestorUser = $this->gestorUser();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.reset-password', $gestorUser))->assertRedirect();

        $crudo = AuditLog::where('action', AuditService::USUARIO_CONTRASENA_RESTABLECIDA)->sole()->getRawOriginal('payload');

        $this->assertStringNotContainsString('password', strtolower($crudo));
    }

    public function test_el_hash_del_operador_no_aparece_al_crearlo(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('users.store'), [
            'name' => 'Nuevo',
            'email' => 'nuevo@test',
            'password' => 'SecretoDelOperador123!',
        ]);

        $crudo = AuditLog::where('action', AuditService::OPERADOR_CREADO)->sole()->getRawOriginal('payload');

        $this->assertStringNotContainsString('SecretoDelOperador123!', $crudo);

        // Tampoco el hash: `log()` se llama con `validated`, que ya trae la
        // contraseña hasheada, así que el hash era el valor que estaba a punto
        // de guardarse.
        $hash = User::where('email', 'nuevo@test')->sole()->password;
        $this->assertStringNotContainsString($hash, $crudo);

        // Lo que sí identifica al operador nuevo se conserva.
        $this->assertSame('nuevo@test', AuditLog::where('action', AuditService::OPERADOR_CREADO)->sole()->payload['email']);
    }

    public function test_el_filtro_oculta_tambien_las_claves_anidadas(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Un token con nombre no obvio, que es el caso que un filtro de
        // nombres exactos dejaría pasar.
        app(AuditService::class)->log('prueba.anidada', [
            'config' => [
                'refresh_endpoint' => 'https://x.test',
                'X-Auth' => 'valor-secreto',
            ],
            'lista' => [
                ['client_secret' => 'secreto-en-lista'],
            ],
        ]);

        $entrada = AuditLog::where('action', 'prueba.anidada')->sole();
        $crudoReal = $entrada->getRawOriginal('payload');

        $this->assertStringNotContainsString('valor-secreto', $crudoReal);
        $this->assertStringNotContainsString('secreto-en-lista', $crudoReal);
        // Se busca 'x.test' y no la URL entera porque MySQL escapa las barras
        // invertidas al serializar el JSON; el valor sí está, con su nombre.
        $this->assertStringContainsString('x.test', $crudoReal, 'Lo que no es secreto debe seguir guardándose.');
        $this->assertSame(
            'https://x.test',
            $entrada->payload['config']['refresh_endpoint'],
        );
    }

    public function test_los_otros_cambios_de_un_usuario_si_se_guardan(): void
    {
        $gestorUser = $this->gestorUser();

        $this->actingAs(User::factory()->admin()->create());

        $this->put(route('gestor-users.update', $gestorUser), [
            'nombre_completo' => 'Ana Silva Editada',
            'cpf' => '12345678909',
            'empresa' => 'klios',
        ])->assertRedirect();

        $cambios = AuditLog::where('action', AuditService::USUARIO_ACTUALIZADO)->sole()->payload['cambios'];

        $this->assertSame(['desde' => 'Ana Silva', 'hasta' => 'Ana Silva Editada'], $cambios['nombre_completo']);
        $this->assertArrayNotHasKey('empresa', $cambios, 'Un campo que no cambió no se registra.');
    }

    public function test_el_login_fallido_se_registra_con_su_origen(): void
    {
        $this->post('/login', ['email' => 'nadie@test', 'password' => 'lo-que-sea']);

        $entrada = AuditLog::where('action', AuditService::LOGIN_FALLIDO)->sole();

        $this->assertSame('nadie@test', $entrada->payload['email']);
        $this->assertNull($entrada->user_id, 'Quien no ha entrado todavía no es un actor conocido.');
        $this->assertSame('web', $entrada->origen);
        $this->assertNotNull($entrada->ip_address);
    }

    private function conTokenDe(ApiAbility $ability): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test', [$ability->value])
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    /** Adagio responde que el CPF no existe: el alta va por «usuario nuevo». */
    private function fakeAdagio(): void
    {
        Subsystem::firstOrCreate(
            ['slug' => 'adagio'],
            [
                'nombre' => 'Adagio',
                'api_url' => 'https://adagio.test',
                // email y password son las credenciales del propio driver
                // contra Adagio, no las del usuario que se da de alta; sin
                // ellas el login falla antes de llegar a la auditoría.
                'api_config' => ['email' => 'bot@test', 'password' => 'bot-secret'],
                'activo' => true,
                'es_proveedor_identidad' => true,
            ],
        );

        Http::preventStrayRequests();
        Http::fake([
            'https://adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token']),
            'https://adagio.test/propietarios/internos' => Http::response(
                ['usuario' => ['id' => 77, 'email' => 'ana.silva@empresa.com.br']],
                200,
            ),
            'https://adagio.test/*' => Http::response(null, 404),
            'https://email.test/mailboxes/*' => Http::response(null, 404),
            'https://email.test/*' => Http::response(['id' => 'email-42']),
        ]);
    }

    private function conCuentaDe(GestorUser $gestorUser, string $slug): UserSubsystemAccount
    {
        // api_url y api_config no son opcionales: sin ellos el driver no tiene
        // contra qué URL llamar y la petición sale a internet de verdad.
        $subsistema = Subsystem::firstOrCreate(
            ['slug' => $slug],
            [
                'nombre' => ucfirst($slug),
                'api_url' => 'https://'.$slug.'.test',
                'api_config' => [],
                'activo' => true,
                'es_proveedor_identidad' => false,
            ],
        );

        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => $slug.'-42',
            'estado' => 'activo',
        ]);
    }

    private function gestorUser(): GestorUser
    {
        return GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana',
            'empresa' => 'klios',
        ]);
    }

    /** @return array<string, mixed> */
    private function altaValida(): array
    {
        return [
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana',
            'empresa' => 'klios',
        ];
    }
}
