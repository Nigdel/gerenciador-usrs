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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubsystemServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_adagio_finds_users_checks_email_and_manages_lifecycle(): void
    {
        $subsystem = $this->subsystem('adagio', ['token' => 'adagio-token']);
        $account = $this->account($subsystem, 'adagio-42');
        Http::fake(function ($request) {
            return match (true) {
                $request->method() === 'POST' => Http::response(['data' => ['id' => 42]]),
                str_ends_with($request->url(), '/usuarios/buscar?cpf=123') => Http::response(['data' => [
                    'id' => 42,
                    'nombre' => 'Ana Silva',
                    'email' => 'ana@example.com',
                    'cpf' => '123',
                    'login' => 'ana.silva',
                ]]),
                str_ends_with($request->url(), '/usuarios/buscar?email=ana%40example.com') => Http::response(['data' => ['id' => 42]]),
                $request->method() === 'GET' && str_contains($request->url(), '/usuarios/adagio-42') => Http::response(['data' => ['estado' => 'activo']]),
                default => Http::response(['ok' => true]),
            };
        });

        $service = app(AdagioService::class);
        $found = $service->findByCpf('123');

        $this->assertSame('Ana Silva', $found['nombre_completo']);
        $this->assertTrue($service->existsByEmail('ana@example.com'));
        $created = $service->createUser($this->userData(), $subsystem);
        $suspended = $service->suspendUser($account, ['motivo_suspension' => 'Licencia']);
        $reactivated = $service->reactivateUser($account);
        $disabled = $service->disableUser($account);
        $status = $service->getUserStatus($account);

        $this->assertTrue($created->success);
        $this->assertSame('42', $created->externalAccountId);
        $this->assertSame('suspendido', $suspended->estado);
        $this->assertSame('activo', $reactivated->estado);
        $this->assertSame('deshabilitado', $disabled->estado);
        $this->assertSame('activo', $status->estado);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/usuarios'));
    }

    public function test_adagio_returns_null_or_failure_for_unsuccessful_requests(): void
    {
        $subsystem = $this->subsystem('adagio');
        $account = $this->account($subsystem, 'missing');
        Http::fake(fn () => Http::response(['error' => 'down'], 500));
        $service = app(AdagioService::class);

        $this->assertNull($service->findByCpf('missing'));
        $this->assertFalse($service->existsByEmail('missing@example.com'));
        $this->assertFalse($service->createUser($this->userData(), $subsystem)->success);
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
}
