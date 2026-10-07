<?php

namespace Tests\Feature\Subsystems\Adagio;

use App\Models\Subsystem;
use App\Services\Subsystems\AdagioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_adagio_finds_a_proprietario_by_cpf(): void
    {
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test/api',
            'api_config' => ['email' => 'service@example.test', 'password' => 'secret'],
            'es_proveedor_identidad' => true,
            'activo' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response([
                'id' => 42,
                'nome' => 'Ana Silva',
                'email' => 'ana@example.test',
                'documento' => '12345678909',
            ]));

        $user = app(AdagioService::class)->findByCpf('123.456.789-09');

        $this->assertSame('Ana Silva', $user['nombre_completo']);
        $this->assertSame('12345678909', $user['cpf']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'documento=12345678909'));
    }

    public function test_adagio_checks_whether_an_email_exists(): void
    {
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test/api',
            'api_config' => ['email' => 'service@example.test', 'password' => 'secret'],
            'es_proveedor_identidad' => true,
            'activo' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response(['id' => 42, 'email' => 'ana@example.test']));

        $exists = app(AdagioService::class)->existsByEmail('ANA@example.test');

        $this->assertTrue($exists);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'email=ana%40example.test'));
    }
}
