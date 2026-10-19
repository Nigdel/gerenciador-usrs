<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\Contracts\UsernameAvailabilityInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chatwoot: se gerencian 2 cuentas distintas dentro de la misma instancia
 * (Klios y Federal), cada una con su propio account_id. El mapeo
 * empresa -> account_id vive en api_config.accounts del subsistema:
 *
 *   'accounts' => ['klios' => 1, 'federal' => 2]
 *
 * En creación se resuelve por $userData['empresa']; en las operaciones
 * posteriores (suspender/reactivar/deshabilitar/estado), como solo se
 * recibe la cuenta (UserSubsystemAccount), se resuelve por la empresa del
 * GestorUser dueño de esa cuenta ($account->user->empresa) — así no hace
 * falta guardar el account_id por separado en cada fila.
 */
class ChatwootService extends BaseSubsystemService implements SubsystemConnectionInterface, UsernameAvailabilityInterface
{
    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        $accounts = $this->mapaCuentas($subsystem);
        if (empty($accounts)) {
            return SubsystemOperationResult::fail('Falta api_config.accounts del subsistema Chatwoot');
        }

        $resultados = [];
        $fallo = false;

        foreach ($accounts as $empresa => $accountId) {
            $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}");

            $ok = $response->successful();
            $fallo = $fallo || ! $ok;

            $resultados[$empresa] = [
                'account_id' => $accountId,
                'ok' => $ok,
                'status' => $response->status(),
            ];
        }

        if ($fallo) {
            return SubsystemOperationResult::fail('Una o más cuentas de Chatwoot fallaron la verificación', $resultados);
        }

        return SubsystemOperationResult::ok(mensaje: 'Conexión y autenticación con Chatwoot exitosas (todas las cuentas)', raw: $resultados);
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountId($subsystem, $userData['empresa'] ?? null);
        $inboxId = $this->resolverInboxId($subsystem, $userData['empresa'] ?? null); // falla rápido, antes de crear nada

        $payload = [
            'name' => $userData['nombre_completo'],
            'email' => $userData['email_personal'] ?? $userData['usuario'].'@'.($userData['empresa'] ?? 'empresa'),
            'role' => 'agent',
        ];

        $teamIds = $this->resolverTeamIds($userData, $subsystem);

        // `team_ids` no se manda en el alta: Chatwoot solo acepta
        // name/email/role/availability/auto_offline en POST /agents y descarta
        // el resto en silencio. La pertenencia a equipos se crea después con
        // team_members (ver asignarAEquipos()).
        $response = $this->http($subsystem)->post("/api/v1/accounts/{$accountId}/agents", $payload);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('Chatwoot rechazó la creación del agente', $response->json() ?? []);
        }

        $agentId = $response->json('id');
        $raw = array_merge($response->json() ?? [], ['account_id' => $accountId, 'inbox_id' => $inboxId]);

        $asignacion = $this->asignarAInbox($subsystem, $accountId, $inboxId, $agentId);
        $asignacionEquipos = $this->asignarAEquipos($subsystem, $accountId, $agentId, $teamIds);

        $configuracionAdicional = [
            'account_id' => $accountId,
            'role' => 'agent',
            'team_ids' => $teamIds,
            'inbox_ids' => [$inboxId],
            'inbox_asignado' => $asignacion->success,
            'teams_asignados' => $asignacionEquipos->success,
        ];

        $raw['inbox_asignado'] = $asignacion->success;
        $raw['teams_asignados'] = $asignacionEquipos->success;

        if (! $asignacion->success) {
            // El agente ya existe en Chatwoot: se informa el problema sin perder su id,
            // para que no quede huérfano y se pueda reintentar solo la asignación.
            $this->log('Agente creado pero no se pudo añadir al inbox', [
                'agent_id' => $agentId,
                'inbox_id' => $inboxId,
                'raw' => $asignacion->raw,
            ]);

            $raw['inbox_error'] = $asignacion->raw;
        }

        if (! $asignacionEquipos->success) {
            $this->log('Agente creado pero no se pudo añadir a uno o más equipos', [
                'agent_id' => $agentId,
                'team_ids' => $teamIds,
                'raw' => $asignacionEquipos->raw,
            ]);

            $raw['teams_error'] = $asignacionEquipos->raw;
        }

        $mensaje = match (true) {
            ! $asignacion->success && ! $asignacionEquipos->success => 'Agente creado, pero no se pudo añadir a la caja de entrada ni a los equipos.',
            ! $asignacion->success => 'Agente creado, pero no se pudo añadir a la caja de entrada.',
            ! $asignacionEquipos->success => 'Agente creado, pero no se pudo añadir a uno o más equipos.',
            default => null,
        };

        return SubsystemOperationResult::ok(
            credencialUsuario: $userData['usuario'],
            externalAccountId: (string) $agentId,
            estado: 'activo',
            mensaje: $mensaje,
            raw: $raw,
            configuracionAdicional: $configuracionAdicional,
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        return $this->disableUser($account); // Chatwoot no distingue suspendido de deshabilitado a nivel de agente
    }

    /**
     * Reactivar en Chatwoot es **volver a crear el agente**, no hacer un PATCH.
     *
     * Chatwoot no tiene un estado "deshabilitado" para un agente: disableUser()
     * lo elimina del account, así que el PATCH de availability que se usaba
     * antes ibas sobre un id que ya no existía y la reactivación no llegaba a
     * hacer nada. El alta se delega en createUser() para no duplicar el payload
     * (equipos, empresa, email), y el nuevo external_account_id se devuelve en
     * el resultado para que quien reactiva lo persista: el id anterior ya no
     * vale.
     */
    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $usuario = $account->user;

        if ($usuario === null) {
            return SubsystemOperationResult::fail('La cuenta de Chatwoot no tiene un usuario gestionado asociado');
        }

        $configuracion = $account->configuracion_adicional;
        $teamIds = is_array($configuracion) ? ($configuracion['team_ids'] ?? []) : [];

        $resultado = $this->createUser([
            'nombre_completo' => $usuario->nombre_completo,
            'usuario' => $usuario->usuario,
            'email_personal' => $usuario->email_personal,
            'empresa' => $usuario->empresa,
            // El agente se recrea desde cero: sin esto perdería los equipos
            // que tenía asignados antes de la baja, que están guardados en la
            // propia cuenta. Las cuentas creadas antes de guardar la
            // configuración no tienen el campo y se tratan como sin equipos.
            'subsystem_config' => [
                'chatwoot' => [
                    'teams' => $teamIds,
                ],
            ],
        ], $account->subsystem);

        if (! $resultado->success) {
            return $resultado;
        }

        return SubsystemOperationResult::ok(
            externalAccountId: $resultado->externalAccountId,
            estado: 'activo',
            mensaje: 'Agente recreado en Chatwoot.',
            raw: $resultado->raw,
            configuracionAdicional: $resultado->configuracionAdicional,
        );
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountIdDeCuenta($account);

        // El DELETE es correcto aquí y no un descuido: es la única forma de
        // quitarle el acceso a un agente de Chatwoot. La contrapartida es que
        // la baja en este subsistema sí es una eliminación real, y por eso la
        // reactivación consiste en crear el agente de nuevo.
        $response = $this->http($account->subsystem)->delete(
            "/api/v1/accounts/{$accountId}/agents/{$account->external_account_id}",
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo eliminar el agente en Chatwoot', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function supportsDeleteUser(): bool
    {
        return true;
    }

    /**
     * Eliminar la cuenta es la misma llamada que disableUser(): Chatwoot no
     * tiene un estado "deshabilitado" para el agente, así que DELETE /agents/{id}
     * es lo único que le quita el acceso. Se delega para no duplicar el
     * endpoint; solo cambia el estado que se informa al orquestador.
     *
     * Sin esto el borrado desde la pantalla de cuentas eliminaba solo la fila
     * local y dejaba al agente vivo dentro de Chatwoot.
     */
    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $resultado = $this->disableUser($account);

        if (! $resultado->success) {
            return $resultado;
        }

        return SubsystemOperationResult::ok(
            estado: 'eliminado',
            mensaje: $resultado->mensaje,
            raw: $resultado->raw,
        );
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountIdDeCuenta($account);

        $response = $this->http($account->subsystem)->get(
            "/api/v1/accounts/{$accountId}/agents/{$account->external_account_id}",
        );

        // 404/410 como "deshabilitado" y no como error: en Chatwoot el agente
        // que no existe está deshabilitado, porque no hay otro estado para
        // expresarlo. Sin esto, la baja (Fase 2.6) nunca se confirmaría y el
        // usuario quedaría sin marcar pese a haber perdido el acceso.
        if (in_array($response->status(), [404, 410], true)) {
            return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
        }

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Chatwoot', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: $response->json('availability') === 'online' ? 'activo' : 'deshabilitado', raw: $response->json() ?? []);
    }

    public function supportsUpdateUser(): bool
    {
        return true;
    }

    /**
     * El nombre y el email son los dos únicos atributos que un agente tiene en
     * Chatwoot. La disponibilidad es de la sesión, no del usuario, así que no
     * forma parte de una sincronización de datos.
     */
    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountIdDeCuenta($account);

        $payload = ['name' => $userData['nombre_completo']];

        if (! blank($userData['email_personal'] ?? null)) {
            $payload['email'] = $userData['email_personal'];
        }

        $response = $this->http($account->subsystem)->patch(
            "/api/v1/accounts/{$accountId}/agents/{$account->external_account_id}",
            $payload,
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo actualizar el agente en Chatwoot', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    /**
     * @return array<int, int>
     */
    private function resolverTeamIds(array $userData, Subsystem $subsystem): array
    {
        $selectedTeams = $userData['subsystem_config']['chatwoot']['teams']
            ?? $userData['subsystem_config'][$subsystem->slug]['teams']
            // Forma ya filtrada que algunos llamadores pasan directamente:
            // ['subsystem_config' => ['teams' => [...]]].
            ?? $userData['subsystem_config']['teams']
            ?? $userData['teams']
            ?? $userData['team_ids']
            ?? [];

        if (! is_array($selectedTeams)) {
            return [];
        }

        $normalized = array_values(array_unique(array_map(static fn ($teamId) => (int) $teamId, $selectedTeams)));

        return array_values(array_filter($normalized, static fn (int $teamId): bool => $teamId > 0));
    }

    /**
     * @return array<int, array{id: mixed, name: string}>
     */
    public function listTeams(Subsystem $subsystem, ?string $empresa): array
    {
        $accountId = $this->resolverAccountId($subsystem, $empresa);

        $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/teams");

        if ($response->failed()) {
            throw new RuntimeException("No se pudo consultar los equipos de Chatwoot para la empresa '{$empresa}'.");
        }

        $payload = $response->json();
        $items = $payload['payload'] ?? $payload['data'] ?? $payload ?? [];

        if (! is_array($items)) {
            return [];
        }

        $teams = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? $item['team_id'] ?? null;
            $name = $item['name'] ?? $item['title'] ?? $item['label'] ?? null;

            if ($id === null || $name === null) {
                continue;
            }

            $teams[] = ['id' => $id, 'name' => (string) $name];
        }

        return $teams;
    }

    /**
     * Asocia el agente recién creado a los equipos seleccionados.
     *
     * El alta de agentes de Chatwoot no acepta `team_ids`, así que cada
     * pertenencia se crea con POST /teams/{team_id}/team_members. Se intentan
     * todos los equipos aunque uno falle, para no dejar sin asignar los demás
     * por un error puntual.
     *
     * @param  array<int, int>  $teamIds
     */
    private function asignarAEquipos(Subsystem $subsystem, int|string $accountId, int|string $agentId, array $teamIds): SubsystemOperationResult
    {
        $fallos = [];

        foreach ($teamIds as $teamId) {
            $response = $this->http($subsystem)->post(
                "/api/v1/accounts/{$accountId}/teams/{$teamId}/team_members",
                ['user_ids' => [(int) $agentId]],
            );

            if ($response->failed()) {
                $fallos[$teamId] = $response->json() ?? ['status' => $response->status()];
            }
        }

        if ($fallos !== []) {
            return SubsystemOperationResult::fail(
                'No se pudo añadir el agente a uno o más equipos de Chatwoot',
                $fallos,
            );
        }

        return SubsystemOperationResult::ok();
    }

    // -----------------------------------------------------------------
    // Asignación de agente a inbox (caja de entrada)
    // -----------------------------------------------------------------
    private function asignarAInbox(Subsystem $subsystem, int|string $accountId, int|string $inboxId, int|string $agentId): SubsystemOperationResult
    {
        $response = $this->http($subsystem)->post(
            "/api/v1/accounts/{$accountId}/inbox_members",
            [
                'inbox_id' => (int) $inboxId,
                'user_ids' => [(int) $agentId],
            ],
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail(
                'No se pudo añadir el agente a la caja de entrada de Chatwoot',
                $response->json() ?? [],
            );
        }

        return SubsystemOperationResult::ok(raw: $response->json() ?? []);
    }

    // -----------------------------------------------------------------
    // Resolución de cuenta (account_id) según empresa
    // -----------------------------------------------------------------

    /**
     * @return array<string, int|string> ej. ['klios' => 1, 'federal' => 2]
     */
    private function mapaCuentas(Subsystem $subsystem): array
    {
        return $subsystem->api_config['accounts'] ?? [];
    }

    // -----------------------------------------------------------------
    // Determina la caja de entrada de la cuenta (Inbox_id) según empresa
    // -----------------------------------------------------------------
    private function resolverInboxId(Subsystem $subsystem, ?string $empresa): int|string
    {
        $clave = strtolower((string) $empresa);
        $inboxes = $subsystem->api_config['inboxes'] ?? [];

        if (! isset($inboxes[$clave])) {
            throw new RuntimeException(
                "[Chatwoot] No hay inbox configurado para la empresa '{$empresa}'. "
                .'Configura api_config.inboxes en el subsistema, ej.: {"klios": 16, "federal": 13}. '
                .'Empresas configuradas: '.implode(', ', array_keys($inboxes)),
            );
        }

        return $inboxes[$clave];
    }

    private function resolverAccountId(Subsystem $subsystem, ?string $empresa): int|string
    {
        $clave = strtolower((string) $empresa);
        $accounts = $this->mapaCuentas($subsystem);

        if (! isset($accounts[$clave])) {
            throw new RuntimeException(
                "[Chatwoot] No hay account_id configurado para la empresa '{$empresa}'. "
                .'Configura api_config.accounts en el subsistema, ej.: {"klios": 1, "federal": 2}. '
                .'Empresas configuradas: '.implode(', ', array_keys($accounts)),
            );
        }

        return $accounts[$clave];
    }

    /**
     * Para operaciones sobre una cuenta ya existente (suspender, reactivar,
     * deshabilitar, estado), la empresa se toma del GestorUser dueño de la
     * cuenta, no del payload de la request.
     */
    private function resolverAccountIdDeCuenta(UserSubsystemAccount $account): int|string
    {
        return $this->resolverAccountId($account->subsystem, $account->user->empresa ?? null);
    }

    /**
     * Fija una nueva contraseña para el usuario en Chatwoot (Platform API).
     *
     * Requiere `platform_token` en api_config y que la Platform App tenga permiso sobre el usuario.
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        if (blank($account->external_account_id)) {
            return SubsystemOperationResult::fail('La cuenta no tiene un ID externo de usuario en Chatwoot');
        }

        try {
            $response = $this->platformHttp($account->subsystem)->patch(
                "/platform/api/v1/users/{$account->external_account_id}",
                ['password' => $newPassword],
            );
        } catch (\Throwable $e) {
            report($e);

            return SubsystemOperationResult::fail('Error de comunicación con Chatwoot al cambiar la contraseña');
        }

        if ($response->failed()) {
            return SubsystemOperationResult::fail(
                match ($response->status()) {
                    401 => 'Token de Platform App inválido en Chatwoot',
                    403 => 'La Platform App no tiene permiso sobre este usuario en Chatwoot',
                    404 => 'El usuario no existe en Chatwoot',
                    422 => 'Chatwoot rechazó la contraseña (no cumple su política)',
                    default => 'No se pudo cambiar la contraseña del usuario en Chatwoot',
                },
                $this->sanitizeRaw($response->json() ?? []),
            );
        }

        return SubsystemOperationResult::ok(
            estado: $account->estado->value,
            raw: $this->sanitizeRaw($response->json() ?? []),
        );
    }

    private function sanitizeRaw(array $raw): array
    {
        unset($raw['access_token'], $raw['password']);

        return $raw;
    }

    /**
     * En Chatwoot lo que debe ser único es el **email** del agente, no el login
     * (el nombre puede repetirse sin problema). La comprobación usa el mismo
     * email que construiría createUser(), porque es contra ese valor contra el
     * que únicos.
     *
     * Chatwoot no tiene filtro de búsqueda de agentes en la API pública de
     * cuenta, así que se listan y se comparan en local. Si la empresa no tiene
     * account configurado se devuelve null: createUser() fallaría con
     * RuntimeException en ese caso, así que no es una comprobación que el
     * generador pueda hacer por sí solo.
     */
    public function loginEnUso(string $login, ?string $empresa, Subsystem $subsystem): ?bool
    {
        try {
            $accountId = $this->resolverAccountId($subsystem, $empresa);
        } catch (RuntimeException $exception) {
            $this->log('No se pudo resolver el account_id de Chatwoot para comprobar el login', [
                'login' => $login,
                'empresa' => $empresa,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $email = strtolower($login.'@'.($subsystem->api_config['dominio'] ?? ($empresa ?? 'empresa').'.com.br'));

        try {
            $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/agents", ['page' => 1]);
        } catch (\Throwable $exception) {
            $this->log('No se pudo comprobar la disponibilidad del login en Chatwoot', [
                'login' => $login,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $payload = $response->json();
        $agentes = $payload['payload'] ?? $payload['data'] ?? $payload ?? [];

        if (! is_array($agentes)) {
            return null;
        }

        foreach ($agentes as $agente) {
            if (! is_array($agente)) {
                continue;
            }

            $emailAgente = $agente['email'] ?? null;

            if (is_string($emailAgente) && strcasecmp(trim($emailAgente), $email) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cliente HTTP para la Platform API de Chatwoot (/platform/api/v1/...).
     * Usa `platform_token`, distinto del token de agente que usa http().
     */
    protected function platformHttp(Subsystem $subsystem): PendingRequest
    {
        $config = $subsystem->api_config ?? [];
        $baseUrl = rtrim((string) $subsystem->api_url, '/');

        if ($baseUrl === '') {
            throw new RuntimeException("El subsistema {$subsystem->getKey()} no tiene api_url configurada");
        }

        if (blank($config['platform_token'] ?? null)) {
            throw new RuntimeException("El subsistema {$subsystem->getKey()} no tiene platform_token configurado");
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->connectTimeout((int) ($config['connect_timeout'] ?? 5))
            ->timeout((int) ($config['timeout'] ?? 15))
            ->withHeaders(['api_access_token' => $config['platform_token']]);
    }
}
