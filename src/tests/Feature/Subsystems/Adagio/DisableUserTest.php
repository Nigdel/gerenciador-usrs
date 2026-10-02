<?php

namespace Tests\Feature\Subsystems\Adagio;

use App\Services\Subsystems\AdagioService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DisableUserTest extends SubsystemTestCase
{
    public function test_adagio_disables_the_proprietario(): void
    {
        $account = $this->makeAccount('adagio', [
            'email' => 'service@example.test',
            'password' => 'secret',
            'entidad_default' => 'klios',
        ], apiUrl: 'https://adagio.test/api');
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response(['estado' => 'deshabilitado']));

        $result = app(AdagioService::class)->disableUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('deshabilitado', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/proprietarios/internos/adagio-42'));
    }
}
