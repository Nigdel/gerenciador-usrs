<?php

namespace Tests\Feature\Subsystems\Glpi;

use App\Models\Subsystem;
use App\Services\Subsystems\GlpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_glpi_creates_a_user_when_the_login_is_not_found(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://glpi.test/apirest.php/search/User*' => Http::response(['data' => []]),
            'https://glpi.test/apirest.php/User' => Http::response(['id' => 42]),
        ]);

        $result = app(GlpiService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@example.test',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('42', $result->externalAccountId);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/User')
            && ($request->data()['input']['name'] ?? null) === 'ana.silva');
    }

    public function test_glpi_reuses_an_existing_user_after_verifying_its_status(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://glpi.test/apirest.php/search/User*' => Http::response(['data' => [['', '', 'glpi-42']]]),
            'https://glpi.test/apirest.php/User/glpi-42' => Http::response(['id' => 42, 'is_deleted' => 0, 'is_active' => true]),
        ]);

        $result = app(GlpiService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@example.test',
            'cpf' => '12345678909',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        $this->assertSame('Usuario ya existía en GLPI, se reutilizó', $result->mensaje);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/User/glpi-42'));
    }

    public function test_glpi_does_not_reuse_an_existing_deleted_user(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://glpi.test/apirest.php/search/User*' => Http::response(['data' => [['', '', 'glpi-42']]]),
            'https://glpi.test/apirest.php/User/glpi-42' => Http::response(['id' => 42, 'is_deleted' => 1, 'is_active' => false]),
        ]);

        $result = app(GlpiService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@example.test',
            'cpf' => '12345678909',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('borrado', $result->estado);
        $this->assertSame('Usuario ya existía en GLPI, pero está borrado', $result->mensaje);
        Http::assertSentCount(2);
    }
}
