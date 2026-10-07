<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sprint 3.2 — What the two POST endpoints promise.
 *
 * The contract here is what an integration is coded against, so it is written
 * as the README describes it: a 201 that carries `operacion_id` and rows that
 * are still `pendiente`, a 422 that names the field, a 409 that names the
 * operation to wait for, and a `login_no_verificado` that is present even when
 * it is null.
 *
 * That last one is the reason for the file. A key that only appears when there
 * is something to warn about is a key an integration will read with `??` and
 * never notice went missing; here it is always there, and that is asserted.
 *
 * The 401/403 and 409 behaviour already has its own coverage in
 * ApiAuthenticationTest and ConcurrentOperationsTest — what is repeated here is
 * only the error code table from this endpoint's own point of view.
 */
class ProvisioningContractTest extends TestCase
{
    use RefreshDatabase;

    private Subsystem $adagio;

    private Subsystem $email;

    protected function setUp(): void
    {
        parent::setUp();

        // Adagio is the identity provider, so it is always consulted for the
        // CPF even when it will not answer.
        $this->adagio = Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test',
            // 'dominio' no es opcional: sin él, loginEnUso() no puede armar el
            // email que Adagio usa como credencial y devuelve null ("no se pudo
            // comprobar"), con lo que el generador rechaza los 100 candidatos y
            // el alta falla con un 422 en vez de proponer un login.
            'api_config' => [
                'email' => 'a@b.com',
                'password' => 'secret',
                'dominio' => 'empresa.com.br',
                // Al crear un propietario, Adagio exige una entidad con la que
                // resolver el contexto, y solo admite 'klios' o 'federal'. Con
                // otra el driver lanza y el alta se responde 502, que no es lo
                // que estos tests comprueban.
                'entidad_default' => 'klios',
            ],
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);

        $this->email = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'api_url' => 'https://email.test',
            'api_config' => [],
            'activo' => true,
            'es_proveedor_identidad' => false,
        ]);
    }

    /**
     * A real token, because with an empty `sanctum.guard` a web session does
     * not authenticate the API and `Sanctum::actingAs()` answers true to every
     * ability.
     *
     * @param  array<int, ApiAbility>  $abilities
     */
    private function conToken(array $abilities): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test', array_map(fn (ApiAbility $a) => $a->value, $abilities))
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    /**
     * Adagio answers nothing useful for the CPF, so the alta goes down the
     * "new user" path and has to propose a login.
     */
    private function adagioSinElUsuario(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            // Adagio authenticates with a JWT before anything else, so the
            // login call is answered first and the catch-all 404 below is what
            // the CPF lookup gets: not found.
            'https://adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token']),
            // Mismo endpoint, dos papeles: el GET busca al propietario por CPF
            // (404 = no está, y por eso el alta propone login) y el POST lo
            // crea. Un unico 404 para los dos haría fallar el alta en 422.
            'https://adagio.test/propietarios/internos' => Http::response(
                ['usuario' => ['id' => 77, 'email' => 'ana.silva@empresa.com.br']],
                200,
            ),
            'https://adagio.test/*' => Http::response(null, 404),
            // El sondeo de disponibilidad pide GET /mailboxes/{direccion} y
            // entiende un 404 como «no existe, login libre». Un 200 con un
            // cuerpo haría creer que el login ya está ocupado, y entonces los
            // cien candidatos se rechazan y el alta falla.
            'https://email.test/mailboxes/*' => Http::response(null, 404),
            'https://email.test/*' => Http::response(['id' => 'email-42']),
        ]);
    }

    private function payloadAlta(array $extra = []): array
    {
        return array_merge([
            'cpf' => '12345678909',
            'nombre_completo' => 'Ana Silva',
            'empresa' => 'Empresa Teste',
            'password_general' => 'Password123!',
        ], $extra);
    }

    // ------------------------------------------------------------------
    // 201 — the accepted shape
    // ------------------------------------------------------------------

    public function test_el_alta_responde_201_con_el_id_de_la_operacion_y_las_filas_pendientes(): void
    {
        $this->adagioSinElUsuario();

        $respuesta = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['subsistemas' => ['email']]))
            ->assertCreated();

        $respuesta->assertJsonStructure([
            'usuario' => ['id', 'nombre_completo', 'cpf', 'usuario'],
            'operacion_id',
            'tipo',
            'estado',
            'subsistemas' => [['subsistema', 'estado', 'mensaje', 'intentos']],
            'login_no_verificado',
        ]);

        // The operation id is a uuid that really exists: it is what the
        // integration polls until `terminado` is true.
        $operacion = ProvisioningOperation::query()
            ->where('uuid', $respuesta->json('operacion_id'))
            ->sole();

        $this->assertSame('alta', $respuesta->json('tipo'));
        $this->assertSame(OperationStatus::EnCurso->value, $respuesta->json('estado'));
        $this->assertSame(1, $operacion->cuentas()->count());

        // With QUEUE_CONNECTION=sync the jobs already ran inside the request,
        // so what the 201 promised — "pending, work is queued" — is checked
        // against the row itself rather than against the response body.
        $this->assertSame(
            OperationAccountStatus::Ok,
            $operacion->cuentas()->sole()->estado,
            'El cuerpo del job debe haber resuelto la única fila.',
        );
    }

    public function test_el_alta_crea_una_fila_por_subsistema_solicitado(): void
    {
        $this->adagioSinElUsuario();

        $respuesta = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['subsistemas' => ['adagio', 'email']]))
            ->assertCreated();

        $this->assertCount(2, $respuesta->json('subsistemas'));
        $this->assertSame(
            ['adagio', 'email'],
            $respuesta->json('subsistemas.*.subsistema'),
        );
    }

    public function test_sin_subsistemas_da_de_alta_en_todos_los_activos(): void
    {
        $this->adagioSinElUsuario();

        $respuesta = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta())
            ->assertCreated();

        $this->assertSame(['adagio', 'email'], $respuesta->json('subsistemas.*.subsistema'));

        // Inactive subsystems are not provisioned into; 'todos los activos' is
        // the documented meaning and an integration would rely on it.
        $this->email->update(['activo' => false]);

        $otra = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['cpf' => '98765432100']))
            ->assertCreated();

        $this->assertSame(['adagio'], $otra->json('subsistemas.*.subsistema'));
    }

    public function test_el_alta_no_devuelve_la_contrasena_general(): void
    {
        $this->adagioSinElUsuario();

        $cuerpo = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['subsistemas' => ['email']]))
            ->assertCreated()
            ->getContent();

        // It is stored hashed on the user and it travels inside the operation
        // payload, encrypted — but never out through a response.
        $this->assertStringNotContainsString('Password123!', $cuerpo);
    }

    // ------------------------------------------------------------------
    // login_no_verificado
    // ------------------------------------------------------------------

    public function test_login_no_verificado_esta_presente_aunque_sea_null(): void
    {
        $this->adagioSinElUsuario();

        $respuesta = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['subsistemas' => ['email']]))
            ->assertCreated();

        // Every subsystem answered, so there is nothing to warn about — and the
        // key is still there. A key that only appears when there is a problem
        // is one an integration reads with `??` and never notices went missing.
        $this->assertArrayHasKey('login_no_verificado', $respuesta->json());
        $this->assertNull($respuesta->json('login_no_verificado'));
    }

    public function test_avisa_cuando_el_login_no_se_pudo_verificar_en_algun_subsistema(): void
    {
        // Adagio is the one that goes out, so the login is chosen without being
        // confirmed against it: the integration has to know before handing over
        // the credentials, instead of finding out later from a duplicate error.
        Http::preventStrayRequests();
        Http::fake([
            'https://adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token']),
            // Dos llamadas distintas al mismo endpoint, y esta es la que
            // importa: la del sondeo de disponibilidad. La búsqueda por CPF
            // tiene que seguir contestando «no está» —si también se cae, el
            // alta se aborta antes incluso de proponer un login y no hay nada
            // que avisar—.
            // El email va URL-encoded en la query, así que se decide por la
            // petición y no por un patrón de URL.
            'https://adagio.test/*' => function (Request $peticion) {
                // La búsqueda por CPF también lleva query, así que lo que
                // distingue una llamada de la otra es el parámetro.
                if (str_contains($peticion->url(), 'email=')) {
                    throw new ConnectionException('Connection timed out');
                }

                return Http::response(null, 404);
            },
            'https://email.test/mailboxes/*' => Http::response(null, 404),
            'https://email.test/*' => Http::response(['id' => 'email-42']),
        ]);

        $respuesta = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['subsistemas' => ['email']]))
            ->assertCreated();

        $aviso = $respuesta->json('login_no_verificado');

        $this->assertNotNull($aviso, 'Un subsistema caído al proponer el login debe avisar.');
        $this->assertStringContainsString('adagio', $aviso);
    }

    public function test_el_login_propuesto_se_sigue_pudiendo_fijar_a_mano(): void
    {
        $this->adagioSinElUsuario();

        $respuesta = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta([
                'subsistemas' => ['email'],
                'usuario' => 'login.fijado',
            ]))
            ->assertCreated();

        // Fixing the login by hand is the documented way out of the warning
        // above, so it has to be honoured.
        $this->assertSame('login.fijado', $respuesta->json('usuario.usuario'));
        $this->assertNull($respuesta->json('login_no_verificado'));
    }

    // ------------------------------------------------------------------
    // 422 — validation
    // ------------------------------------------------------------------

    public function test_sin_cpf_responde_422_nombrando_el_campo(): void
    {
        $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', ['nombre_completo' => 'Ana Silva', 'empresa' => 'E'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpf');
    }

    public function test_un_subsistema_inexistente_responde_422(): void
    {
        $this->adagioSinElUsuario();

        $respuesta = $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['subsistemas' => ['no-existe']]))
            ->assertStatus(422);

        $this->assertStringContainsString('subsistemas.0', $respuesta->json('errors') ? implode(',', array_keys($respuesta->json('errors'))) : '');
    }

    public function test_una_contrasena_corta_responde_422(): void
    {
        $this->adagioSinElUsuario();

        // Eight characters is the floor the drivers rely on; letting a shorter
        // one through would only fail later, in the subsystem.
        $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['password_general' => 'corta']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password_general');
    }

    public function test_un_cpf_nuevo_sin_nombre_ni_empresa_responde_422_con_el_mensaje(): void
    {
        // This one is not a field error: the payload validates, and the
        // situation does not allow it. 422 and not 400, because the same
        // request can be accepted later without changing a single field.
        $this->adagioSinElUsuario();

        $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', ['cpf' => '12345678909'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'nombre_completo y empresa son obligatorios cuando el CPF no existe en Adagio');
    }

    public function test_un_422_no_crea_el_usuario(): void
    {
        $this->adagioSinElUsuario();

        $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', ['cpf' => '12345678909'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('gestor_users', ['cpf' => '12345678909']);
    }

    // ------------------------------------------------------------------
    // 502 — infrastructure
    // ------------------------------------------------------------------

    public function test_un_subsistema_caido_responde_502_sin_el_detalle_de_la_excepcion(): void
    {
        // The message of a non-domain exception can carry a column name, an
        // LDAP URL or a stack trace. Whoever receives it can do nothing with
        // it — and whoever reads it later can learn a lot.
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Connection to 10.1.2.2:389 refused'));

        $this->conToken([ApiAbility::Provisionar])
            ->postJson('/api/usuarios/provisionar', $this->payloadAlta(['subsistemas' => ['email']]))
            ->assertStatus(502)
            ->assertJsonPath('message', 'No se pudo completar la operación: el servicio no está disponible.')
            ->assertJsonMissing(['message' => 'Connection to 10.1.2.2:389 refused']);
    }

    // ------------------------------------------------------------------
    // The suspension endpoint
    // ------------------------------------------------------------------

    public function test_la_suspension_responde_con_el_id_de_la_operacion(): void
    {
        $gestor = $this->usuarioConCuentaEnEmail();

        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['active' => false])]);

        $respuesta = $this->conToken([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', [
                'cpf' => $gestor->cpf,
                'motivo_suspension' => 'Licencia médica',
                'subsistemas' => ['email'],
            ])
            ->assertOk();

        $respuesta->assertJsonStructure([
            'operacion_id', 'tipo', 'estado', 'subsistemas' => [['subsistema', 'estado', 'mensaje', 'intentos']],
        ]);

        $this->assertSame('suspension', $respuesta->json('tipo'));

        // The id is a real uuid, not a token: it is what the integration then
        // polls with GET /api/usuarios/operaciones/{uuid}.
        $operacion = ProvisioningOperation::query()
            ->where('uuid', $respuesta->json('operacion_id'))
            ->sole();

        $this->assertSame(OperationType::Suspension, $operacion->tipo);
        $this->assertSame($gestor->id, $operacion->gestor_user_id);

        $this->assertDatabaseHas('user_subsystem_accounts', [
            'id' => $gestor->subsystemAccounts()->sole()->id,
            'estado' => 'suspendido',
            'motivo_suspension' => 'Licencia médica',
        ]);
    }

    public function test_la_suspension_sin_motivo_responde_422(): void
    {
        $gestor = $this->usuarioConCuentaEnEmail();

        $this->conToken([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', ['cpf' => $gestor->cpf])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo_suspension');
    }

    public function test_la_suspension_necesita_cpf_o_usuario(): void
    {
        $this->conToken([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', ['motivo_suspension' => 'Licencia médica'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cpf', 'usuario']);
    }

    public function test_la_suspension_acepta_el_login_en_vez_del_cpf(): void
    {
        $gestor = $this->usuarioConCuentaEnEmail();

        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['active' => false])]);

        // Integrations that only have the login are a normal case, not a corner.
        $this->conToken([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', [
                'usuario' => $gestor->usuario,
                'motivo_suspension' => 'Licencia médica',
            ])
            ->assertOk();
    }

    public function test_la_suspension_de_un_usuario_que_no_existe_responde_422(): void
    {
        // Adagio resuelve el CPF primero: si no contesta, el alta no se decide
        // por sus datos sino porque no se pudo saber quién es.
        Http::preventStrayRequests();
        Http::fake([
            'https://adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token']),
            'https://adagio.test/*' => Http::response(null, 404),
        ]);

        // Neither a typo nor a 404: the payload was well formed, the person is
        // simply not there, and the same call can work later.
        $this->conToken([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', [
                'cpf' => '00000000000',
                'motivo_suspension' => 'Licencia médica',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_el_409_avisa_de_la_operacion_que_esta_bloqueando(): void
    {
        $gestor = $this->usuarioConCuentaEnEmail();

        // An operation already open on this person. The client has to be able to
        // wait for it, so the uuid comes back with the 409.
        $bloqueante = app(ProvisioningOperationService::class)->describir(
            OperationType::Alta,
            $gestor,
            collect([$this->email]),
        );

        Http::preventStrayRequests();

        $respuesta = $this->conToken([ApiAbility::Suspender])
            ->postJson('/api/usuarios/suspender', [
                'cpf' => $gestor->cpf,
                'motivo_suspension' => 'Licencia médica',
            ])
            ->assertStatus(409);

        $this->assertSame($bloqueante->uuid, $respuesta->json('operacion_id'));
    }

    private function usuarioConCuentaEnEmail(): GestorUser
    {
        $gestor = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);

        UserSubsystemAccount::create([
            'gestor_user_id' => $gestor->id,
            'subsystem_id' => $this->email->id,
            'credencial_usuario' => 'ana.silva@empresa.test',
            'external_account_id' => 'EXT-1',
            'estado' => 'activo',
        ]);

        return $gestor;
    }
}
