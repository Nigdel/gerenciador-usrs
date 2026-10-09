<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    /**
     * Record an audit entry.
     */
    public function log(string $action, ?array $payload = null, ?Request $request = null): void
    {
        $request ??= request();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'payload' => $payload,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'performed_at' => now(),
        ]);
    }
}
