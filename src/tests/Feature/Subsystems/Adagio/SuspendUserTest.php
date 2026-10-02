<?php

namespace Tests\Feature\Subsystems\Adagio;

use App\Services\Subsystems\AdagioService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class SuspendUserTest extends SubsystemTestCase
{
    public function test_adagio_suspends_the_proprietario(): void
    {
        $account = $this->makeAccount('adagio', [
            'email' => 'service@example.test',
            'password' => 'secret',
        ], apiUrl: 'https://adagio.test/api');
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response([]));

        $result = app(AdagioService::class)->suspendUser($account, [
            'motivo_suspension' => 'Licencia',
            'inicio_suspension' => now(),
            'fin_suspension' => null,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('suspendido', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/proprietarios/internos/adagio-42/suspender'));
    }
}
