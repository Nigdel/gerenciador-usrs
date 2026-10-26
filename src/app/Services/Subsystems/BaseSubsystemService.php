<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemServiceInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
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
        $baseUrl = rtrim((string) $subsystem->api_url, '/');

        if ($baseUrl === '') {
            throw new \RuntimeException("El subsistema {$subsystem->getKey()} no tiene api_url configurada");
        }

        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->connectTimeout((int) ($config['connect_timeout'] ?? 5))
            ->timeout((int) ($config['timeout'] ?? 15));

        if (! empty($config['auth_basic']) && is_array($config['auth_basic'])) {
            $request = $request->withBasicAuth(
                $config['auth_basic']['usuario'] ?? '',
                $config['auth_basic']['password'] ?? '',
            );
        } elseif (! empty($config['token'])) {
            $request = ! empty($config['auth_header'])
                ? $request->withHeaders([$config['auth_header'] => $config['token']])
                : $request->withToken($config['token']);
        }

        if (! empty($config['headers']) && is_array($config['headers'])) {
            $request = $request->withHeaders($config['headers']);
        }

        return $request;
    }

    protected function log(string $mensaje, array $contexto = []): void
    {
        Log::channel(config('logging.default'))->info('[gestor:'.static::class.'] '.$mensaje, $contexto);
    }

    public function supportsDeleteUser(): bool
    {
        return false;
    }

    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('Este subsistema no admite eliminación remota de usuarios.');
    }

    public function supportsUpdateUser(): bool
    {
        return false;
    }

    /**
     * Actualizar los datos de contacto en remoto es opcional (Fase 2.7): un
     * driver que no lo implemente devuelve este fallo en vez de fingir que se
     * sincronizó. El orquestador distingue "el subsistema no lo admite" de
     * "el subsistema falló", y por eso supportsUpdateUser() va aparte en vez de
     * deducirse de que este método falló.
     */
    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('Este subsistema no admite actualizar los datos del usuario.');
    }

    public function verificaEstadoRemoto(): bool
    {
        return true; // por defecto, los demás subsistemas siguen verificando
    }

    /**
     * Cuántas veces se consulta el estado remoto antes de dar por fallida la
     * confirmación de un cambio de estado, y cuánto se espera entre lecturas.
     *
     * Un subsistema de consistencia inmediata no necesita reintentar; uno que
     * replica (Entra ID) sí, y lo declara en su driver. api_config puede
     * pisarlo con state_confirmation_attempts / state_confirmation_delay_ms.
     */
    public function intentosConfirmacionEstado(): int
    {
        return 1;
    }

    public function retardoConfirmacionEstadoMs(): int
    {
        return 0;
    }
}
