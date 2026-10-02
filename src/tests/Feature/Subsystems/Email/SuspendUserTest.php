<?php

namespace Tests\Feature\Subsystems\Email;

use App\Services\Subsystems\EmailService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class SuspendUserTest extends SubsystemTestCase
{
    public function test_email_suspends_the_mailbox_with_a_reason(): void
    {
        $account = $this->makeAccount('email');
        Http::preventStrayRequests();
        Http::fake(['https://email.test/mailboxes/email-42' => Http::response([])]);

        $result = app(EmailService::class)->suspendUser($account, ['motivo_suspension' => 'Licencia']);

        $this->assertTrue($result->success);
        $this->assertSame('suspendido', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && ($request['active'] ?? null) === false
            && ($request['reason'] ?? null) === 'Licencia');
    }
}
