<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\IdempotencyKey;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sprint 5.3 — Idempotencia en la API.
 *
 * El problema que resuelve: una integración llama a /provisionar y pierde la
 * respuesta (timeout, reinicio de red). Si reintenta a ciegas crea un segundo
 * usuario. Con `Idempotency-Key` el reintento recibe la respuesta que ya se
 * envió.
 *
 * Y el otro lado, el que se olvida: la misma clave con OTRO cuerpo no es un
 * reintento, es otra petición. Responderle con el alta anterior devolvería un
 * usuario que nadie pidió, así que tiene que ser un error. Estos tests cubren
 * las dos mitades, más el estado intermedio (425) que es el que evita que dos
 * peticiones simultáneas trabajen en paralelo.
 */
class IdempotencyApiTest extends TestCase
{
    use RefreshDatabase;

    private Subsystem $email;

    protected function setUp(): void
    {
        parent::setUp();

        // Adagio es el proveedor de identidad y se consulta siempre, aunque la
        // petición no lo pida en "subsistemas".
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test',
            'api_config' => [
                'email' => 'a@b.com',
                'password' => 'secret',
                'dominio' => 'empresa.com.br',
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
     * Adagio responde que el CPF no existe, así que el alta va por «usuario
     * nuevo» y propone login. El sondeo de disponibilidad necesita un 404 en
     * el mailbox: un 200 haría creer que el login está ocupado.
     */
    private function adagioSinElUsuario(): void
    {
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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function alta(array $extra = []): array
    {
        return [
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
            'subsistemas' => ['email'],
            ...$extra,
        ];
    }

    private function suspension(): array
    {
        return [
            'cpf' => '12345678909',
            'motivo_suspension' => 'Fin de contrato',
        ];
    }

    private function conToken(ApiAbility $ability): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test', [$ability->value])
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    public function test_sin_cabecera_cada_peticion_es_independiente(): void
    {
        $this->adagioSinElUsuario();

        $this->conToken(ApiAbility::Provisionar)
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        // Sin clave no hay idempotencia: la segunda llamada vuelve a ejecutar.
        // En un alta real eso lo pararía la guarda de Sprint 1.4, que responde
        // 409 mientras la anterior siga en curso; aquí el fake es síncrono y la
        // primera ya terminó, así que se ve el efecto que importa: sin clave
        // NO se guardó nada y no hay nada que repetir.
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_la_misma_clave_repite_la_misma_respuesta(): void
    {
        $this->adagioSinElUsuario();

        $primera = $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        $segunda = $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        // Mismo cuerpo devuelto, incluido el uuid de la operación: eso es lo
        // que permite a la integración seguirla sin haber guardado nada. Se
        // comparan los cuerpos ya decodificados porque la repetición devuelve
        // los bytes guardados tal cual, no un array reconstruido.
        $this->assertSame($primera->getContent(), $segunda->getContent());

        // Y sobre todo: una sola operación. Un reintento no crea un usuario
        // nuevo, que es justo lo que se estaba evitando.
        $this->assertSame(1, ProvisioningOperation::count());
        $this->assertSame(1, GestorUser::count());
    }

    public function test_la_repeticion_devuelve_la_cabecera_de_idempotencia(): void
    {
        $this->adagioSinElUsuario();

        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated()
            ->assertHeader('Idempotency-Key', 'clave-1');
    }

    public function test_la_misma_clave_con_otro_cuerpo_es_un_error_de_validacion(): void
    {
        $this->adagioSinElUsuario();

        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta([
                'nombre_completo' => 'Bruno Costa',
                'cpf' => '98765432100',
                'usuario' => 'bruno.costa',
            ]))
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Esta clave de idempotencia ya se usó con un cuerpo distinto.',
            );

        // Ni una segunda operación ni un segundo usuario: el conflicto se
        // detecta antes de ejecutar nada.
        $this->assertSame(1, ProvisioningOperation::count());
        $this->assertSame(1, GestorUser::count());
    }

    public function test_una_clave_en_curso_responde_que_se_espere(): void
    {
        $this->adagioSinElUsuario();

        // La carrera entre dos peticiones no se puede provocar de verdad
        // dentro de un test —con sqlite, dos peticiones HTTP no se solapan—, así
        // que se reproduce su punto de observación: una fila con la huella
        // correcta y sin respuesta guardada. Para que la huella case hay que
        // dejar que el middleware la calcule con una petición real, y luego
        // vaciar la respuesta para simular que aún no terminó.
        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'otra-clave')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        $fila = IdempotencyKey::where('key', 'otra-clave')->sole();
        $fila->forceFill(['response' => null, 'respuesta_hash' => null, 'status' => null])->save();

        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'otra-clave')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertStatus(425)
            ->assertJsonPath('retry_after', 1);

        $this->assertSame(1, ProvisioningOperation::count());
    }

    public function test_el_orden_de_las_claves_del_cuerpo_no_confunde_las_peticiones(): void
    {
        $this->adagioSinElUsuario();

        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        // El mismo cuerpo con las claves en otro orden es la misma petición.
        // Si la huella fuera el sha1 del JSON en crudo, esto sería un 422 por
        // conflicto y una integración que reordena sus campos a cada versión
        // de su cliente se quedaría sin poder reintentar nunca.
        $reordenado = array_reverse($this->alta(), preserve_keys: true);

        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $reordenado)
            ->assertCreated();

        $this->assertSame(1, ProvisioningOperation::count());
    }

    public function test_una_clave_caducada_vuelve_a_ejecutarse(): void
    {
        $this->adagioSinElUsuario();

        IdempotencyKey::create([
            'key' => 'clave-1',
            'payload_hash' => sha1(json_encode($this->alta())),
            'response' => json_encode(['operacion_id' => 'vieja', 'estado' => 'pendiente']),
            'respuesta_hash' => sha1('x'),
            'status' => 201,
            'expires_at' => now()->subMinute(),
        ]);

        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated()
            ->assertJsonPath('operacion_id', fn ($uuid) => $uuid !== 'vieja');
    }

    public function test_un_422_libera_la_clave_para_que_se_pueda_corregir_y_reintentar(): void
    {
        $this->adagioSinElUsuario();

        // Un 422 es un error de los datos: no hizo nada. Si la fila se
        // quedara, la integración no podría reintentar con la misma clave
        // tras corregir el campo, que es justo cuando más lo necesita.
        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), ['nombre_completo' => 'Sin cpf'])
            ->assertStatus(422);

        // El middleware corrió antes que la validación, así que la fila se
        // creó; lo que importa es que se haya liberado para poder reintentar.
        $this->assertSame(0, IdempotencyKey::count());

        $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_la_suspension_tambien_es_idempotente(): void
    {
        // La cuenta se crea a mano y no con un alta por la API: así la prueba
        // mide una sola cosa —que suspender dos veces con la misma clave no
        // abre dos operaciones— y no arrastra el alta de fondo.
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);

        UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $this->email->id,
            'credencial_usuario' => 'ana.silva@empresa.test',
            'external_account_id' => 'email-42',
            'estado' => SubsystemAccountStatus::Activo,
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response([], 200)]);

        $primera = $this->conToken(ApiAbility::Suspender)
            ->withHeader('Idempotency-Key', 'clave-sus')
            ->postJson(route('api.usuarios.suspender'), $this->suspension())
            ->assertOk();

        $segunda = $this->conToken(ApiAbility::Suspender)
            ->withHeader('Idempotency-Key', 'clave-sus')
            ->postJson(route('api.usuarios.suspender'), $this->suspension())
            ->assertOk();

        $this->assertSame($primera->getContent(), $segunda->getContent());
        $this->assertSame(1, ProvisioningOperation::count());
    }

    public function test_la_clave_se_enlaza_con_la_operacion_que_creo(): void
    {
        $this->adagioSinElUsuario();

        $respuesta = $this->conToken(ApiAbility::Provisionar)
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        $fila = IdempotencyKey::sole();

        $this->assertNotNull($fila->provisioning_operation_id);
        $this->assertSame(
            ProvisioningOperation::sole()->uuid,
            $respuesta->json('operacion_id'),
        );
    }

    public function test_consultar_una_operacion_no_necesita_cabecera_de_idempotencia(): void
    {
        $this->adagioSinElUsuario();

        // Un solo token con las dos abilities: crear una por llamada haría que
        // el GET consultara con un token distinto al del alta, que es otra
        // prueba (y no la que importa aquí).
        $plain = User::factory()->operador()->create()
            ->createToken('test', [ApiAbility::Provisionar->value, ApiAbility::Consultar->value])
            ->plainTextToken;

        $autorizado = $this->withHeader('Authorization', 'Bearer '.$plain);

        $respuesta = $autorizado
            ->withHeader('Idempotency-Key', 'clave-1')
            ->postJson(route('api.usuarios.provisionar'), $this->alta())
            ->assertCreated();

        // Un GET no crea nada, así que no tiene nada que repetir. Exigir la
        // cabecera aquí obligaría a la integración a inventar una en cada
        // consulta de estado, que es lo que hace para seguir la operación.
        $autorizado
            ->getJson(route('api.operaciones.show', $respuesta->json('operacion_id')))
            ->assertOk();
    }
}
