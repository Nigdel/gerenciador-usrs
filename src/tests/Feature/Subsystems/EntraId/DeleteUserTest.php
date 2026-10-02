<?php

namespace Tests\Feature\Subsystems\EntraId;

use App\Services\Subsystems\EntraIdService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DeleteUserTest extends SubsystemTestCase
{
    public function test_entra_id_deletes_the_remote_user(): void
    {
        $account = $this->makeAccount('entraid', ['accounts' => ['klios' => [
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
        ]]]);
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response(['access_token' => 'entra-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/users/entraid-42' => Http::response([], 204),
        ]);

        $service = app(EntraIdService::class);
        $result = $service->deleteUser($account);

        $this->assertTrue($service->supportsDeleteUser());
        $this->assertTrue($result->success);
        $this->assertSame('eliminado', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v1.0/users/entraid-42'));
    }
}
