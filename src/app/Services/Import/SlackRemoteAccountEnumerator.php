<?php

namespace App\Services\Import;

use App\Models\Subsystem;
use App\Services\Subsystems\SlackService;
use RuntimeException;

/**
 * Enumera los usuarios de Slack vía SCIM.
 *
 * Extiende SlackService para reutilizar el cliente HTTP con el token del
 * subsistema. Solo tiene sentido con SCIM habilitado (api_config['scim_habilitado']);
 * si no, no hay cuentas que importar y se devuelve una lista vacía.
 *
 * El email (userName del recurso SCIM) es la identidad: el CPF no viaja en
 * Slack, así que la correlación se hace por login/email.
 */
class SlackRemoteAccountEnumerator extends SlackService implements RemoteAccountEnumerator
{
    public function slug(): string
    {
        return 'slack';
    }

    public function enumerar(Subsystem $subsystem): array
    {
        if (empty($subsystem->api_config['scim_habilitado'])) {
            return [];
        }

        $response = $this->http($subsystem)->get('/scim/v1/Users');

        if ($response->failed()) {
            throw new RuntimeException('No se pudo listar los usuarios de Slack SCIM (HTTP '.$response->status().').');
        }

        $payload = $response->json();
        $recursos = is_array($payload) ? ($payload['Resources'] ?? $payload['data'] ?? $payload) : null;

        if (! is_array($recursos)) {
            return [];
        }

        $cuentas = [];

        foreach ($recursos as $recurso) {
            if (! is_array($recurso) || blank($recurso['userName'] ?? null)) {
                continue;
            }

            $cuentas[] = new RemoteAccount(
                subsystemSlug: $this->slug(),
                externalAccountId: (string) ($recurso['id'] ?? $recurso['userName']),
                login: $this->usuarioDe($recurso['userName']),
                email: $recurso['userName'],
                cpf: null,
                estado: isset($recurso['active']) && $recurso['active'] ? 'activo' : 'deshabilitado',
                meta: $recurso,
            );
        }

        return $cuentas;
    }

    private function usuarioDe(mixed $email): ?string
    {
        $email = strtolower(trim((string) $email));

        if ($email === '' || ! str_contains($email, '@')) {
            return $email;
        }

        $usuario = strstr($email, '@', true);

        return ($usuario === false || $usuario === '') ? $email : $usuario;
    }
}
