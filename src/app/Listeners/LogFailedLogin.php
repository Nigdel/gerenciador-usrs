<?php

namespace App\Listeners;

use App\Services\AuditService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;

class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        /** @var Request $request */
        $request = request();

        app(AuditService::class)->log(
            'api.login.failed',
            [
                'email' => $event->credentials['email'] ?? null,
            ],
            $request
        );
    }
}
