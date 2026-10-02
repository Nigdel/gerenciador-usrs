<?php

namespace Tests\Feature\Subsystems\Glpi;

use App\Services\Subsystems\GlpiService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class SuspendUserTest extends SubsystemTestCase
{
    public function test_glpi_suspends_the_user(): void
    {
        $account = $this->makeAccount('glpi', apiUrl: 'https://glpi.test/apirest.php');
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/apirest.php/User/glpi-42' => Http::response([])]);

        $result = app(GlpiService::class)->suspendUser($account, ['motivo_suspension' => 'Licencia']);

        $this->assertTrue($result->success);
        $this->assertSame('suspendido', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && ($request->data()['input']['is_active'] ?? null) === false);
    }
}
