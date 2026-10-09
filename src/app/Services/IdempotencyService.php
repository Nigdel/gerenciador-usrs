<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Http\Request;

class IdempotencyService
{
    /**
     * Check for existing idempotency entry and return stored response if found.
     */
    public function check(Request $request)
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return null;
        }

        $existing = IdempotencyKey::where('key', $key)->first();
        if ($existing) {
            return $existing->response;
        }

        // store placeholder
        IdempotencyKey::create([
            'key' => $key,
            'payload_hash' => sha1($request->getContent()),
            'response' => null,
        ]);

        return null;
    }

    /**
     * Persist the response for the given idempotency key.
     */
    public function storeResponse(string $key, $response, int $status)
    {
        $record = IdempotencyKey::where('key', $key)->first();
        if (! $record) {
            return;
        }
        $record->response = [
            'status' => $status,
            'data' => $response,
        ];
        $record->save();
    }
}
