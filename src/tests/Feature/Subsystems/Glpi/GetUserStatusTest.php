<?php

namespace Tests\Feature\Subsystems\Glpi;

use App\Services\Subsystems\GlpiService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class GetUserStatusTest extends SubsystemTestCase
{
    public function test_glpi_returns_the_user_status(): void
    {
        $account = $this->makeAccount('glpi', apiUrl: 'https://glpi.test/apirest.php');
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/apirest.php/User/glpi-42' => Http::response(['is_deleted' => 0, 'is_active' => true])]);

        $result = app(GlpiService::class)->getUserStatus($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/User/glpi-42'));
    }
}
