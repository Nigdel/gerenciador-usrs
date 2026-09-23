<?php

namespace Tests\Unit;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\AdagioService;
use App\Services\Subsystems\ChatwootService;
use App\Services\Subsystems\EmailService;
use App\Services\Subsystems\EntraIdService;
use App\Services\Subsystems\GlpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubsystemServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_adagio_finds_users_checks_email_and_manages_lifecycle(): void
    {
        $subsystem = $this->subsystem('adagio', [
            'email' => 'test@example.com',
            'password' => 'test-password',
            'entidad_default' => 'klios',
        ]);
        $subsystem->update([
            'es_proveedor_identidad' => true,
            'activo' => true,
        ]);
        $account = $this->account($subsystem, 'adagio-42');
        Http::fake(function ($request) {
            return match (true) {
                str_contains($request->url(), '/kliosAnalise/login') => Http::response(['token' => 'test-token']),
                $request->method() === 'GET' && str_contains($request->url(), 'documento=12345678901') => Http::response([]),
                $request->method() === 'GET' && str_contains($request->url(), '/proprietarios/internos') => Http::response([
                    'id' => 42,
                    'nome' => 'Ana Silva',
                    'email' => 'ana@example.com',
                    'documento' => '123',
                    'estado' => 'activo',
                ]),
                $request->method() === 'POST' && str_contains($request->url(), '/proprietarios/internos') => Http::response([
                    'usuario' => ['id' => 42, 'email' => 'ana.silva@example.com'],
                ]),
                default => Http::response(['estado' => 'activo']),
            };
        });

        $service = app(AdagioService::class);
        $found = $service->findByCpf('123');

        $this->assertSame('Ana Silva', $found['nombre_completo']);
        $this->assertTrue($service->existsByEmail('ana@example.com'));
        $created = $service->createUser($this->userData() + ['entidad' => 'klios'], $subsystem);
        $suspended = $service->suspendUser($account, [
            'motivo_suspension' => 'Licencia',
            'inicio_suspension' => now(),
            'fin_suspension' => null,
        ]);
        $reactivated = $service->reactivateUser($account);
        $disabled = $service->disableUser($account);
        $status = $service->getUserStatus($account);

        $this->assertTrue($created->success);
        $this->assertSame('42', $created->externalAccountId);
        $this->assertSame('suspendido', $suspended->estado);
        $this->assertSame('activo', $reactivated->estado);
        $this->assertSame('deshabilitado', $disabled->estado);
        $this->assertSame('activo', $status->estado);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/proprietarios/internos'));
    }

    public function test_adagio_returns_null_or_failure_for_unsuccessful_requests(): void
    {
        $apiUrl = env('ADAGIO_API_URL', env('ADAGIO_BASE_URL'));

        $this->assertNotEmpty($apiUrl, 'Configure ADAGIO_API_URL or ADAGIO_BASE_URL to run this live integration test.');


        $subsystem = $this->subsystem('adagio', [
            'email' => env('ADAGIO_EMAIL'),
            'password' => env('ADAGIO_PASSWORD'),
            'timeout' => 5,
        ]);
        $subsystem->update([
            'api_url' => $apiUrl,
            'es_proveedor_identidad' => true,
            'activo' => true,
        ]);

        $account = new UserSubsystemAccount([
            'external_account_id' => 'usuario-inexistente-'.uniqid(),
        ]);
        $account->setRelation('subsystem', $subsystem);
        $service = app(AdagioService::class);

        $this->assertNull($service->findByCpf('cpf-inexistente-'.uniqid()));
        $this->assertFalse($service->existsByEmail('email-inexistente-'.uniqid().'@example.invalid'));
        $this->assertFalse($service->getUserStatus($account)->success);
    }

    public function test_chatwoot_creates_and_manages_an_agent(): void
    {
        $subsystem = $this->subsystem('chatwoot', ['account_id' => 77]);
        $account = $this->account($subsystem, 'chat-9');
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['availability' => 'online']);
            }
            return Http::response(['id' => 9]);
        });
        $service = app(ChatwootService::class);
        $created = $service->createUser($this->userData(), $subsystem);
        $suspended = $service->suspendUser($account, []);
        $reactivated = $service->reactivateUser($account);
        $disabled = $service->disableUser($account);
        $status = $service->getUserStatus($account);

        $this->assertSame('9', $created->externalAccountId);
        $this->assertSame('deshabilitado', $suspended->estado);
        $this->assertSame('activo', $reactivated->estado);
        $this->assertSame('deshabilitado', $disabled->estado);
        $this->assertSame('activo', $status->estado);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/accounts/77/agents'));
    }

    public function test_chatwoot_reports_creation_failures(): void
    {
        $subsystem = $this->subsystem('chatwoot', ['account_id' => 77]);
        Http::fake(fn () => Http::response(['error' => 'invalid'], 422));

        $result = app(ChatwootService::class)->createUser($this->userData(), $subsystem);

        $this->assertFalse($result->success);
        $this->assertSame('Chatwoot rechazó la creación del agente', $result->mensaje);
    }

    public function test_email_creates_and_manages_a_mailbox(): void
    {
        $subsystem = $this->subsystem('email', ['dominio' => 'example.com']);
        $account = $this->account($subsystem, 'mail-3');
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['active' => true]);
            }
            return Http::response(['id' => 3]);
        });
        $service = app(EmailService::class);
        $created = $service->createUser($this->userData(), $subsystem);
        $suspended = $service->suspendUser($account, ['motivo_suspension' => 'Bloqueio']);
        $reactivated = $service->reactivateUser($account);
        $disabled = $service->disableUser($account);
        $status = $service->getUserStatus($account);

        $this->assertSame('ana.silva@example.com', $created->credencialUsuario);
        $this->assertSame('3', $created->externalAccountId);
        $this->assertSame('suspendido', $suspended->estado);
        $this->assertSame('activo', $reactivated->estado);
        $this->assertSame('deshabilitado', $disabled->estado);
        $this->assertSame('activo', $status->estado);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/mailboxes'));
    }

    public function test_email_reports_creation_failures(): void
    {
        $subsystem = $this->subsystem('email');
        Http::fake(fn () => Http::response([], 500));

        $result = app(EmailService::class)->createUser($this->userData(), $subsystem);

        $this->assertFalse($result->success);
        $this->assertSame('No se pudo crear la casilla de correo', $result->mensaje);
    }

    public function test_entra_id_creates_and_manages_a_user(): void
    {
        $subsystem = $this->subsystem('entraid', ['dominio' => 'tenant.onmicrosoft.com']);
        $account = $this->account($subsystem, 'entra-5');
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['accountEnabled' => true]);
            }
            return Http::response(['id' => 'entra-5']);
        });
        $service = app(EntraIdService::class);
        $created = $service->createUser($this->userData(), $subsystem);
        $suspended = $service->suspendUser($account, []);
        $reactivated = $service->reactivateUser($account);
        $disabled = $service->disableUser($account);
        $status = $service->getUserStatus($account);

        $this->assertSame('ana.silva@tenant.onmicrosoft.com', $created->credencialUsuario);
        $this->assertSame('entra-5', $created->externalAccountId);
        $this->assertSame('suspendido', $suspended->estado);
        $this->assertSame('activo', $reactivated->estado);
        $this->assertSame('deshabilitado', $disabled->estado);
        $this->assertSame('activo', $status->estado);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1.0/users'));
    }

    public function test_entra_id_reports_creation_failures(): void
    {
        $subsystem = $this->subsystem('entraid');
        Http::fake(fn () => Http::response([], 400));

        $result = app(EntraIdService::class)->createUser($this->userData(), $subsystem);

        $this->assertFalse($result->success);
        $this->assertSame('Entra ID rechazó la creación del usuario', $result->mensaje);
    }

    public function test_glpi_reuses_existing_users_and_manages_accounts(): void
    {
        $subsystem = $this->subsystem('glpi');
        $account = $this->account($subsystem, 'glpi-8');
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/User?')) {
                return Http::response(['data' => [['id' => 8]]]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['is_active' => true]);
            }
            return Http::response(['id' => 8]);
        });
        $service = app(GlpiService::class);
        $created = $service->createUser($this->userData(), $subsystem);
        $suspended = $service->suspendUser($account, []);
        $reactivated = $service->reactivateUser($account);
        $disabled = $service->disableUser($account);
        $status = $service->getUserStatus($account);

        $this->assertTrue($created->success);
        $this->assertSame('Usuario ya existía en GLPI, se reutilizó', $created->mensaje);
        $this->assertSame('8', $created->externalAccountId);
        $this->assertSame('suspendido', $suspended->estado);
        $this->assertSame('activo', $reactivated->estado);
        $this->assertSame('deshabilitado', $disabled->estado);
        $this->assertSame('activo', $status->estado);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/User'));
    }

    public function test_glpi_creates_users_and_reports_failures(): void
    {
        $subsystem = $this->subsystem('glpi');
        Http::fake([
            '*\/User?*' => Http::response(['data' => []]),
            '*' => Http::response([], 500),
        ]);

        $result = app(GlpiService::class)->createUser($this->userData(), $subsystem);

        $this->assertFalse($result->success);
        $this->assertSame('GLPI rechazó la creación del usuario', $result->mensaje);
    }

    private function subsystem(string $slug, array $apiConfig = []): Subsystem
    {
        return Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => 'https://'.$slug.'.test',
            'api_config' => $apiConfig,
            'activo' => true,
        ]);
    }

    private function account(Subsystem $subsystem, string $externalId): UserSubsystemAccount
    {
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);

        return UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => $externalId,
            'estado' => 'activo',
        ]);
    }

    private function userData(): array
    {
        return [
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'email_personal' => 'ana.silva@example.com',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
            'password_general' => 'Password123!',
        ];
    }

    public function test_adagio_authentication_and_user_lifecycle(): void
{
    /* -----------------------------------------------------------------------
     * 1️⃣  Preparar la configuración “falsa” que usaría el Service.
     * --------------------------------------------------------------------- */
    Config::set('adagio.email',          'test@example.com');
    Config::set('adagio.password',       'test-password');
    Config::set('adagio.entidad_default','klios');

    /* -----------------------------------------------------------------------
     * 2️⃣  Crear el Subsystem (simula la tabla `subsystems`) con los mismos
     *     valores que la configuración – de esa forma el Service podrá leerlos
     *     desde la entidad y no directamente del config (dependiendo de tu impl.).
     * --------------------------------------------------------------------- */
    $subsystem = $this->subsystem('adagio', [
        'email'           => config('adagio.email'),
        'password'        => config('adagio.password'),
        'entidad_default'=> config('adagio.entidad_default'),
    ]);
    $subsystem->update([
        'es_proveedor_identidad' => true,
        'activo'                 => true,
    ]);

    $account = $this->account($subsystem, 'adagio-42');

    /* -----------------------------------------------------------------------
     * 3️⃣  Fake de HTTP – incluimos la respuesta del login y de los demás
     *     endpoints que el Service consume.  Cada respuesta está “hard‑coded”
     *     para que el test sea determinista.
     * --------------------------------------------------------------------- */
    Http::fake(function ($request) {
        // -------------------------------------------------
        // a) Login → devuelve un token que el Service guardará.
        // -------------------------------------------------
        if (str_contains($request->url(), '/kliosAnalise/login')) {
            // Simulamos que la API valida el body JSON
            $expectedBody = [
                'email'            => config('adagio.email'),
                'password'         => config('adagio.password'),
                'entidad_default' => config('adagio.entidad_default'),
            ];

            // Si el payload es distinto lanzamos una excepción para que el test falle.
            if ($request->data() !== $expectedBody) {
                return Http::response(['error' => 'invalid credentials'], 401);
            }

            return Http::response(['token' => 'test-token'], 200);
        }

        // -------------------------------------------------
        // b) Búsqueda por CPF (GET)
        // -------------------------------------------------
        if ($request->method() === 'GET' && str_contains($request->url(), 'documento=12345678901')) {
            return Http::response([]); // “no encontrado”
        }

        // -------------------------------------------------
        // c) Listado de propietarios internos (GET)
        // -------------------------------------------------
        if ($request->method() === 'GET' && str_contains($request->url(), '/proprietarios/internos')) {
            return Http::response([
                'id'      => 42,
                'nome'    => 'Ana Silva',
                'email'   => 'ana@example.com',
                'documento'=> '123',
                'estado'  => 'activo',
            ]);
        }

        // -------------------------------------------------
        // d) Creación de propietario interno (POST)
        // -------------------------------------------------
        if ($request->method() === 'POST' && str_contains($request->url(), '/proprietarios/internos')) {
            return Http::response([
                'usuario' => ['id' => 42, 'email' => 'ana.silva@example.com'],
            ]);
        }

        // -------------------------------------------------
        // e) Cambios de estado (PATCH/PUT) – cualquier otro endpoint
        // -------------------------------------------------
        return Http::response(['estado' => 'activo']);
    });

    /* -----------------------------------------------------------------------
     * 4️⃣  Instanciar el service y disparar la lógica que queremos probar.
     * --------------------------------------------------------------------- */
    $service = app(AdagioService::class);

    // 4.1 → Busca por CPF (debe hacer login internamente antes de la llamada)
    $found = $service->findByCpf('123');
    $this->assertSame('Ana Silva', $found['nombre_completo']);

    // 4.2 → Verifica existencia por email (usa el mismo token)
    $this->assertTrue($service->existsByEmail('ana@example.com'));

    // 4.3 → Crea un usuario externo (usa endpoint POST /proprietarios/internos)
    $created = $service->createUser(
        $this->userData() + ['entidad' => config('adagio.entidad_default')],
        $subsystem
    );

    // 4.4 → Suspend / Reactivate / Disable / Status (cada uno llama a la API)
    $suspended   = $service->suspendUser($account, [
        'motivo_suspension' => 'Licencia',
        'inicio_suspension' => now(),
        'fin_suspension'    => null,
    ]);
    $reactivated = $service->reactivateUser($account);
    $disabled    = $service->disableUser($account);
    $status      = $service->getUserStatus($account);

    /* -----------------------------------------------------------------------
     * 5️⃣  Aserciones de ciclo de vida (las que ya tenías)
     * --------------------------------------------------------------------- */
    $this->assertTrue($created->success);
    $this->assertSame('42', $created->externalAccountId);
    $this->assertSame('suspendido', $suspended->estado);
    $this->assertSame('activo', $reactivated->estado);
    $this->assertSame('deshabilitado', $disabled->estado);
    $this->assertSame('activo', $status->estado);

    /* -----------------------------------------------------------------------
     * 6️⃣  Aserciones específicas de **autenticación**
     * --------------------------------------------------------------------- */
    // 6.1 → Se envió un POST al endpoint de login
    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/kliosAnalise/login');
    });

    // 6.2 → El body del login coincide con los valores de la configuración
    Http::assertSent(function ($request) {
        if (!($request->method() === 'POST' && str_contains($request->url(), '/kliosAnalise/login'))) {
            return false;
        }

        $payload = $request->data(); // Laravel 9+; para versiones anteriores usar $request->json()
        return $payload['email']            === config('adagio.email')
            && $payload['password']         === config('adagio.password')
            && $payload['entidad_default']  === config('adagio.entidad_default');
    });
    
    }
}
