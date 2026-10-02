<?php

namespace Tests\Feature\Subsystems\EntraId;

use App\Services\Subsystems\EntraIdService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class GetUserStatusTest extends SubsystemTestCase
{
    public function test_entra_id_returns_the_user_account_status(): void
    {
        $account = $this->makeAccount('entraid', ['accounts' => ['klios' => [
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
        ]]]);
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response(['access_token' => 'entra-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/users/entraid-42*' => Http::response(['accountEnabled' => true]),
        ]);

        $result = app(EntraIdService::class)->getUserStatus($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/v1.0/users/entraid-42'));
    }
}
