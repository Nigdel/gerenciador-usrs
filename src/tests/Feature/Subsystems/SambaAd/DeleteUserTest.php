<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Services\Subsystems\SambaAdService;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DeleteUserTest extends SubsystemTestCase
{
    public function test_samba_ad_reports_missing_host_when_deleting_a_user(): void
    {
        $account = $this->makeAccount('sambaad', ['host' => '']);

        $service = app(SambaAdService::class);
        $result = $service->deleteUser($account);

        $this->assertTrue($service->supportsDeleteUser());
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Host de Samba AD vacío', $result->mensaje);
    }
}
