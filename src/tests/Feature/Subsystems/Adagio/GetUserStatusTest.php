<?php

namespace Tests\Feature\Subsystems\Adagio;

use App\Services\Subsystems\AdagioService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class GetUserStatusTest extends SubsystemTestCase
{
    public function test_adagio_returns_the_proprietario_status(): void
    {
        $account = $this->makeAccount('adagio', [
            'email' => 'service@example.test',
            'password' => 'secret',
        ], apiUrl: 'https://adagio.test/api');
        $account->subsystem->update(['es_proveedor_identidad' => true]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response(['estado' => 'activo']));

        $result = app(AdagioService::class)->getUserStatus($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/proprietarios/internos/adagio-42'));
    }
}
