<?php

namespace App\Services\Import;

use App\Models\Subsystem;
use App\Services\Subsystems\ChatwootService;
use RuntimeException;
use Throwable;

/**
 * Enumera los agentes de Chatwoot por cada cuenta (empresa) configurada.
 *
 * Extiende ChatwootService para reutilizar el cliente HTTP con el token del
 * subsistema. Chatwoot no expone estados de suspensión: un agente que existe
 * se importa como 'activo' y la "desactivación" se representa por los accesos
 * que tenga, que se leen en vivo (inboxes y equipos) y quedan como la foto
 * inicial de configuracion_adicional, la misma pieza que suspendUser/reactivateUser
 * usan en los drivers de escritura.
 */
class ChatwootRemoteAccountEnumerator extends ChatwootService implements RemoteAccountEnumerator
{
    public function slug(): string
    {
        return 'chatwoot';
    }

    public function enumerar(Subsystem $subsystem): array
    {
        $accounts = $subsystem->api_config['accounts'] ?? [];

        if ($accounts === []) {
            throw new RuntimeException('[Import Chatwoot] Falta api_config.accounts del subsistema.');
        }

        $cuentas = [];

        foreach ($accounts as $empresa => $accountId) {
            $agentes = $this->agentesDelAccount($subsystem, (string) $accountId);

            $accessos = $this->accesosEnVivo($subsystem, (string) $accountId);

            foreach ($agentes as $agente) {
                $agentId = (string) ($agente['id'] ?? '');

                if ($agentId === '') {
                    continue;
                }

                $cuentas[] = new RemoteAccount(
                    subsystemSlug: $this->slug(),
                    externalAccountId: $agentId,
                    login: isset($agente['email']) ? strstr((string) $agente['email'], '@', true) ?: (string) $agente['email'] : null,
                    email: $agente['email'] ?? null,
                    cpf: null, // Chatwoot no conoce el CPF: la correlación va por email
                    estado: 'activo',
                    nombreCompleto: $agente['name'] ?? null,
                    meta: array_merge($agente, ['empresa' => $empresa, 'account_id' => $accountId]),
                    configInicial: [
                        'account_id' => $accountId,
                        'role' => $agente['role'] ?? 'agent',
                        'inbox_ids' => $accessos->inboxIds[$agentId] ?? [],
                        'team_ids' => $accessos->teamIds[$agentId] ?? [],
                        'importado' => true,
                    ],
                );
            }
        }

        return $cuentas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function agentesDelAccount(Subsystem $subsystem, string $accountId): array
    {
        try {
            $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/agents");
        } catch (Throwable $exception) {
            throw new RuntimeException('No se pudo consultar los agentes de Chatwoot: '.$exception->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('No se pudo listar los agentes de Chatwoot (HTTP '.$response->status().').');
        }

        $items = $this->items($response->json());

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    /**
     * Lectura en vivo de los accesos del account groupada por agente.
     */
    private function accesosEnVivo(Subsystem $subsystem, string $accountId): AccesosChatwoot
    {
        $accesos = new AccesosChatwoot;

        try {
            // Inboxes: se leen todas las del account (no solo la configurada),
            // porque hoy el agente puede estar en cualquiera.
            $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/inboxes");

            if (! $response->failed()) {
                foreach ($this->items($response->json()) as $inbox) {
                    if (! is_array($inbox) || ! isset($inbox['id'])) {
                        continue;
                    }

                    $miembros = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/inbox_members/{$inbox['id']}");

                    if ($miembros->failed()) {
                        continue;
                    }

                    foreach ($this->items($miembros->json()) as $miembro) {
                        if (is_array($miembro) && isset($miembro['id'])) {
                            $accesos->inboxIds[(string) $miembro['id']][] = (int) $inbox['id'];
                        }
                    }
                }
            }

            // Equipos: la pertenencia a cada uno requiere leer sus miembros.
            $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/teams");

            if ($response->failed()) {
                return $accesos;
            }

            foreach ($this->items($response->json()) as $equipo) {
                if (! is_array($equipo) || ! isset($equipo['id'])) {
                    continue;
                }

                $miembros = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/teams/{$equipo['id']}/team_members");

                if ($miembros->failed()) {
                    continue;
                }

                foreach ($this->items($miembros->json()) as $miembro) {
                    if (is_array($miembro) && isset($miembro['id'])) {
                        $accesos->teamIds[(string) $miembro['id']][] = (int) $equipo['id'];
                    }
                }
            }
        } catch (Throwable $exception) {
            // Lectura de mejor esfuerzo: sin accesos la foto queda vacía, y
            // la reactivación asigna la inbox configurada por empresa.
            $this->log('Import Chatwoot: no se pudo leer los accesos en vivo', ['error' => $exception->getMessage()]);
        }

        return $accesos;
    }

    /**
     * @return list<mixed>
     */
    private function items(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        $items = $json['payload'] ?? $json['data'] ?? $json;

        return is_array($items) ? array_values($items) : [];
    }
}
