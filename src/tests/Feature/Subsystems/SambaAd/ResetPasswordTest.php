<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Services\Subsystems\SambaAdService;
use Tests\Feature\Subsystems\SubsystemTestCase;

class ResetPasswordTest extends SubsystemTestCase
{
    public function test_samba_ad_reports_missing_host_when_resetting_a_password(): void
    {
        $account = $this->makeAccount('sambaad', ['host' => '']);

        $result = app(SambaAdService::class)->resetPassword($account, 'NewPassword123!');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Host de Samba AD vacío', $result->mensaje);
    }
}
