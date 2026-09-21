<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemServiceInterface;
use App\Models\Subsystem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base común para todos los adaptadores de subsistema. Centraliza la
 * construcción del cliente HTTP a partir de api_url/api_config y el logging,
 * de forma que cada Service concreto solo implemente su lógica específica.
 */
abstract class BaseSubsystemService implements SubsystemServiceInterface
{
    protected function http(Subsystem $subsystem): PendingRequest
    {
        $config = $subsystem->api_config ?? [];
        $request = Http::baseUrl(rtrim((string) $subsystem->api_url, '/'))
            ->acceptJson()
            ->timeout($config['timeout'] ?? 15);

        if (! empty($config['token'])) {
            $request = $request->withToken($config['token']);
        }

        if (! empty($config['headers']) && is_array($config['headers'])) {
            $request = $request->withHeaders($config['headers']);
        }

        if (! empty($config['auth_basic']) && is_array($config['auth_basic'])) {
            $request = $request->withBasicAuth(
                $config['auth_basic']['usuario'] ?? '',
                $config['auth_basic']['password'] ?? '',
            );
        }

        return $request;
    }

    protected function log(string $mensaje, array $contexto = []): void
    {
        Log::channel(config('logging.default'))->info('[gestor:'.static::class.'] '.$mensaje, $contexto);
    }
}
