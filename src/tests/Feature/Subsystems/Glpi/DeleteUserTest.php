<?php

namespace Tests\Feature\Subsystems\Glpi;

use App\Services\Subsystems\GlpiService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DeleteUserTest extends SubsystemTestCase
{
    public function test_glpi_deletes_the_remote_user(): void
    {
        $account = $this->makeAccount('glpi', apiUrl: 'https://glpi.test/apirest.php');
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/apirest.php/User/glpi-42' => Http::response(['id' => 'glpi-42'])]);

        $result = app(GlpiService::class)->deleteUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('eliminado', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/User/glpi-42'));
    }
}
