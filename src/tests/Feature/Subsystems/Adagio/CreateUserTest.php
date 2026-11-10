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

    private function subsistema(array $apiConfig = []): Subsystem
    {
        return Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test/api',
            'api_config' => array_merge([
                'email' => 'service@example.test',
                'password' => 'secret',
                'entidad_default' => 'klios',
            ], $apiConfig),
        ]);
    }

    private function fakeAdagio(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/kliosAnalise/login')) {
                return Http::response(['token' => 'adagio-token']);
            }

            if ($request->method() === 'GET') {
                return Http::response([]);
            }

            return Http::response(['usuario' => ['id' => 42, 'email' => 'ana.silva@klios.com.br']]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@klios.test',
            'empresa' => 'klios',
        ], $extra);
    }

    private function assertEmailEnviado(string $email): void
    {
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/proprietarios/internos')
            && str_contains($request->body(), $email));
    }

    public function test_adagio_creates_a_proprietario_when_the_cpf_is_not_found(): void
    {
        $subsystem = $this->subsistema();
        $this->fakeAdagio();

        $result = app(AdagioService::class)->createUser($this->datos(), $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('42', $result->externalAccountId);
        $this->assertEmailEnviado('ana.silva@klios.com.br');
    }

    public function test_usa_el_dominio_de_la_empresa_ignorando_el_dominio_fijo(): void
    {
        $subsystem = $this->subsistema(['dominio' => 'empresa.com.br']);
        $this->fakeAdagio();

        $result = app(AdagioService::class)->createUser($this->datos(), $subsystem);

        $this->assertTrue($result->success);
        $this->assertEmailEnviado('ana.silva@klios.com.br');
    }

    public function test_federal_usa_el_dominio_institucional(): void
    {
        $subsystem = $this->subsistema(['dominio' => 'empresa.com.br']);
        $this->fakeAdagio();

        $result = app(AdagioService::class)->createUser(
            $this->datos(['empresa' => 'Federal']),
            $subsystem,
        );

        $this->assertTrue($result->success);
        $this->assertEmailEnviado('ana.silva@federalst.com.br');
    }

    public function test_sin_empresa_usa_el_dominio_configurado(): void
    {
        $subsystem = $this->subsistema(['dominio' => 'acme.test']);
        $this->fakeAdagio();

        $result = app(AdagioService::class)->createUser(
            $this->datos(['empresa' => '']),
            $subsystem,
        );

        $this->assertTrue($result->success);
        $this->assertEmailEnviado('ana.silva@acme.test');
    }
}
