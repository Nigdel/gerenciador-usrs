<?php

namespace App\Services\Import;

use App\Models\Subsystem;
use App\Services\Subsystems\EntraIdService;
use RuntimeException;

/**
 * Enumera las cuentas de Microsoft Entra ID por Graph.
 *
 * Extiende EntraIdService para reutilizar la autenticación OAuth2 por tenant
 * (client_credentials + token en caché + reautoría en 401): las cuentas
 * (tenants) se leen de api_config.accounts, cada una con su propio dominio.
 *
 * cpf vive en employeeId; el estado se lee de accountEnabled.
 */
class EntraIdRemoteAccountEnumerator extends EntraIdService implements RemoteAccountEnumerator
{
    public function slug(): string
    {
        return 'entraid';
    }

    public function enumerar(Subsystem $subsystem): array
    {
        $config = $subsystem->api_config ?? [];
        $empresas = array_keys($config['accounts'] ?? [null]);

        $cuentas = [];

        foreach ($empresas as $empresa) {
            $cuentas = array_merge($cuentas, $this->enumerarTenant($subsystem, is_string($empresa) ? $empresa : null));
        }

        return $cuentas;
    }

    /**
     * @return list<RemoteAccount>
     */
    private function enumerarTenant(Subsystem $subsystem, ?string $empresa): array
    {
        $config = $subsystem->api_config ?? [];
        $tenants = $config['accounts'] ?? [];

        $dominio = $empresa === null
            ? ($config['dominio'] ?? null)
            : $tenants[$empresa]['dominio'] ?? null;

        $cuentas = [];
        // Se pagina con @odata.nextLink, que es una URL absoluta: se pide
        // el path relativo para no mezclarlo con el baseUrl del cliente.
        $url = '/v1.0/users';

        do {
            $response = $this->http($subsystem, $empresa)->get($url, [
                '$select' => 'id,accountEnabled,userPrincipalName,mail,employeeId,displayName',
                '$top' => 100,
            ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'No se pudo listar los usuarios de Entra ID'
                    .($empresa ? " para la empresa '{$empresa}'" : '')
                    .' (HTTP '.$response->status().').',
                );
            }

            foreach ($response->json('value') ?? [] as $usuario) {
                if (! is_array($usuario) || blank($usuario['id'] ?? null)) {
                    continue;
                }

                $upn = $usuario['userPrincipalName'] ?? null;

                $cuentas[] = new RemoteAccount(
                    subsystemSlug: $this->slug(),
                    externalAccountId: (string) ($usuario['id'] ?? ''),
                    login: isset($upn, $dominio) && str_ends_with(strtolower((string) $upn), '@'.strtolower((string) $dominio))
                        ? strstr($upn, '@', true)
                        : $upn,
                    email: $usuario['mail'] ?? null,
                    cpf: blank($usuario['employeeId'] ?? null) ? null : (string) $usuario['employeeId'],
                    estado: $usuario['accountEnabled'] ? 'activo' : 'deshabilitado',
                    nombreCompleto: $usuario['displayName'] ?? null,
                    meta: array_merge($usuario, [
                        'empresa' => $empresa,
                        'upn' => $upn,
                        'dominio' => $dominio,
                    ]),
                );
            }

            $url = $this->siguientePagina($response->json('@odata.nextLink'));
        } while ($url !== null);

        return $cuentas;
    }

    /**
     * Convierte el nextLink absoluto de Graph al path+query relativo que el
     * cliente (baseUrl fija a Graph) puede pedir. Null si no está.
     */
    private function siguientePagina(mixed $nextLink): ?string
    {
        if (! is_string($nextLink) || $nextLink === '') {
            return null;
        }

        $path = parse_url($nextLink, PHP_URL_PATH);
        $query = parse_url($nextLink, PHP_URL_QUERY);

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $query ? $path.'?'.$query : $path;
    }
}
