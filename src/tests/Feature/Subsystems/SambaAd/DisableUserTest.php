<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Services\Subsystems\SambaAdService;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DisableUserTest extends SubsystemTestCase
{
    public function test_samba_ad_reports_missing_host_when_disabling_a_user(): void
    {
        $account = $this->makeAccount('sambaad', ['host' => '']);

        $result = app(SambaAdService::class)->disableUser($account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Host de Samba AD vacío', $result->mensaje);
    }
}
