<?php

namespace Tests\Feature\Subsystems\Adagio;

use App\Models\Subsystem;
use App\Services\Subsystems\AdagioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_adagio_creates_a_proprietario_when_the_cpf_is_not_found(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test/api',
            'api_config' => [
                'email' => 'service@example.test',
                'password' => 'secret',
                'entidad_default' => 'klios',
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/kliosAnalise/login')) {
                return Http::response(['token' => 'adagio-token']);
            }

            if ($request->method() === 'GET') {
                return Http::response([]);
            }

            return Http::response(['usuario' => ['id' => 42, 'email' => 'ana@klios.test']]);
        });

        $result = app(AdagioService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@klios.test',
            'empresa' => 'klios',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('42', $result->externalAccountId);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/proprietarios/internos'));
    }
}
