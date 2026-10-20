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
use Throwable;

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
 *
 * Chatwoot no tiene estados de suspensión ni de deshabilitado para un agente:
 * desactivar una cuenta es quitarle el acceso (desasociarlo de su caja de
 * entrada y de sus equipos) y el estado vive en nuestro sistema. Por eso
 * suspender y deshabilitar **no borran** al agente —eso es deleteUser()— y la
 * reactivación solo tiene que devolverle los accesos que tenía guardados en
 * configuracion_adicional.
 *
 * configuracion_adicional funciona como una foto de los accesos del agente
 * (inbox_ids y team_ids). Al quitar el acceso la foto se amplía con lo que el
 * agente tenga realmente en Chatwoot en ese momento, pero **nunca se reduce**:
 * así un reintento tras un fallo parcial, o suspender y luego deshabilitar, no
 * pierden los accesos que hay que devolver al reactivar.
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
        $inboxIds = $this->resolverInboxIdsDeAlta($userData, $subsystem); // falla rápido, antes de crear nada

        $payload = [
            'name' => $userData['nombre_completo'],
            'email' => $this->emailDeAgente($userData, $subsystem),
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
        $raw = array_merge($response->json() ?? [], ['account_id' => $accountId, 'inbox_id' => $inboxIds[0] ?? null, 'inbox_ids' => $inboxIds]);

        $asignacion = $this->asignarAInboxes($subsystem, $accountId, $inboxIds, $agentId);
        $asignacionEquipos = $this->asignarAEquipos($subsystem, $accountId, $agentId, $teamIds);

        $configuracionAdicional = [
            'account_id' => $accountId,
            'role' => 'agent',
            'team_ids' => $teamIds,
            'inbox_ids' => $inboxIds,
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
                'inbox_ids' => $inboxIds,
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

    /**
     * Chatwoot no tiene un estado remoto de "suspendido" ni de "deshabilitado"
     * para un agente: desactivarlo es quitarle el acceso (caja de entrada y
     * equipos) y el estado vive en nuestro sistema.
     *
     * Por eso este driver no verifica el estado remoto: la confirmación se hace
     * contra lo que devuelve la propia operación.
     */
    public function verificaEstadoRemoto(): bool
    {
        return false;
    }

    /**
     * Suspender en Chatwoot es **quitarle el acceso** al agente, no borrarlo.
     *
     * Chatwoot no tiene estados de suspensión ni de deshabilitado para un
     * agente, así que la desactivación se hace desasociándolo de su caja de
     * entrada y de sus equipos. El agente sigue existiendo, lo que deja la
     * operación reversible: la reactivación solo tiene que devolverle esos
     * mismos accesos (ver reactivateUser()).
     *
     * Devuelve en configuracionAdicional la foto de los accesos que tenía, con
     * inbox_asignado y teams_asignados en false. Quien llama debe persistirla.
     */
    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        $resultado = $this->desactivarAgente($account);

        if (! $resultado->success) {
            return $resultado;
        }

        return SubsystemOperationResult::ok(
            externalAccountId: $account->external_account_id,
            estado: 'suspendido',
            mensaje: 'Agente desasociado de su caja de entrada y de sus equipos en Chatwoot.',
            raw: $resultado->raw,
            configuracionAdicional: $resultado->configuracionAdicional,
        );
    }

    /**
     * Deshabilitar en Chatwoot es lo mismo que suspender: quitarle el acceso al
     * agente. La diferencia entre los dos estados es solo local (suspendido o
     * deshabilitado), porque en Chatwoot no existe esa distinción.
     */
    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $resultado = $this->desactivarAgente($account);

        if (! $resultado->success) {
            return $resultado;
        }

        return SubsystemOperationResult::ok(
            externalAccountId: $account->external_account_id,
            estado: 'deshabilitado',
            mensaje: 'Agente desasociado de su caja de entrada y de sus equipos en Chatwoot.',
            raw: $resultado->raw,
            configuracionAdicional: $resultado->configuracionAdicional,
        );
    }

    /**
     * Reactivar en Chatwoot es **devolverle al agente sus accesos**.
     *
     * 1. Comprueba que el agente exista (buscarAgente(): por id y, como
     *    respaldo, por email).
     * 2. Si no existe, lo crea de nuevo con los accesos guardados y termina: el
     *    id nuevo se devuelve en el resultado para que quien reactiva lo
     *    persista, porque el anterior ya no vale.
     * 3. Si existe, le asigna la caja de entrada y los equipos guardados en
     *    configuracion_adicional.
     */
    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $subsistema = $account->subsystem;

        if (! $subsistema instanceof Subsystem) {
            return SubsystemOperationResult::fail('La cuenta no tiene un subsistema válido en Chatwoot.');
        }

        $accountId = $this->resolverAccountIdDeCuenta($account);

        try {
            $agente = $this->buscarAgente(
                $subsistema,
                $accountId,
                $account->external_account_id,
                $this->emailsCandidatos($account),
            );
        } catch (Throwable $exception) {
            // No se pudo listar: no se sabe si existe, y recrearlo "por si
            // acaso" duplicaría al agente. Se falla y se reintenta.
            report($exception);

            return SubsystemOperationResult::fail('No se pudo verificar el agente en Chatwoot: '.$exception->getMessage());
        }

        if ($agente === null) {
            return $this->crearAgente($account);
        }

        // Si se encontró por email con otro id (p. ej. un intento anterior lo
        // recreó pero no llegó a persistirse), se usa el id real: el resultado
        // lo devuelve en externalAccountId.
        return $this->asignarAccesos($account, $accountId, $agente['id'] ?? $account->external_account_id);
    }

    public function supportsDeleteUser(): bool
    {
        return true;
    }

    /**
     * Eliminar la cuenta sí es una eliminación real: borra al agente del
     * account de Chatwoot (DELETE /agents/{id}).
     *
     * Esto es distinto de disableUser(), que solo le quita el acceso: borrar
     * al agente es definitivo y no se puede deshacer reactivando.
     */
    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountIdDeCuenta($account);

        $response = $this->http($account->subsystem)->delete(
            "/api/v1/accounts/{$accountId}/agents/{$account->external_account_id}",
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo eliminar el agente en Chatwoot', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'eliminado', raw: $response->json() ?? []);
    }

    /**
     * Estado del agente en Chatwoot.
     *
     * No es un estado de cuenta: la disponibilidad es de la sesión y un agente
     * al que se le han quitado los accesos sigue existiendo. Por eso este
     * driver no verifica el estado remoto (verificaEstadoRemoto() es false) y
     * la confirmación de una suspensión o de una reactivación se hace contra el
     * resultado de la propia operación.
     *
     * Chatwoot no expone GET /agents/{id}, así que el agente se busca en el
     * listado de la cuenta.
     */
    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountIdDeCuenta($account);

        try {
            $agente = $this->buscarAgente(
                $account->subsystem,
                $accountId,
                $account->external_account_id,
                $this->emailsCandidatos($account),
            );
        } catch (Throwable $exception) {
            report($exception);

            return SubsystemOperationResult::fail('No se pudo consultar el estado en Chatwoot: '.$exception->getMessage());
        }

        // Un agente que no existe está deshabilitado: no hay otro estado para
        // expresarlo.
        if ($agente === null) {
            return SubsystemOperationResult::ok(estado: 'deshabilitado');
        }

        return SubsystemOperationResult::ok(estado: ($agente['availability'] ?? null) === 'online' ? 'activo' : 'deshabilitado', raw: $agente);
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

    // -----------------------------------------------------------------
    // Búsqueda de agentes
    // -----------------------------------------------------------------

    /**
     * Busca un agente en el listado de la cuenta, por id y, como respaldo, por
     * email.
     *
     * Chatwoot no expone GET /agents/{id} (solo index, create, update y
     * destroy), así que la existencia se comprueba listando.
     *
     * Lanza RuntimeException si no se puede listar: "no pude listar" no es lo
     * mismo que "no existe", y confundirlos haría recrear agentes que ya están.
     *
     * @param  array<int, string>  $emails
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException
     */
    private function buscarAgente(Subsystem $subsystem, int|string $accountId, int|string|null $agentId, array $emails = []): ?array
    {
        $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/agents");

        if ($response->failed()) {
            throw new RuntimeException('No se pudo listar los agentes de Chatwoot (HTTP '.$response->status().')');
        }

        $agentes = $this->itemsDe($response->json());

        if (! blank($agentId)) {
            foreach ($agentes as $agente) {
                if (is_array($agente) && (string) ($agente['id'] ?? '') === (string) $agentId) {
                    return $agente;
                }
            }
        }

        $emails = array_map(static fn ($email) => strtolower(trim((string) $email)), array_filter($emails, static fn ($email) => ! blank($email)));

        if ($emails !== []) {
            foreach ($agentes as $agente) {
                if (is_array($agente) && in_array(strtolower(trim((string) ($agente['email'] ?? ''))), $emails, true)) {
                    return $agente;
                }
            }
        }

        return null;
    }

    /**
     * Emails con los que el agente pudo haberse creado: el personal del gestor
     * y el que se construye por defecto.
     *
     * @return array<int, string>
     */
    private function emailsCandidatos(UserSubsystemAccount $account): array
    {
        $usuario = $account->user;

        if ($usuario === null) {
            return [];
        }

        return array_values(array_filter([
            $usuario->email_personal,
            $this->emailPorDefecto((string) $usuario->usuario, $usuario->empresa, $account->subsystem),
        ], static fn ($email) => ! blank($email)));
    }

    /**
     * Email que se usa al crear el agente: el personal del gestor si lo tiene
     * y, si no, el construido por defecto.
     */
    private function emailDeAgente(array $userData, Subsystem $subsystem): string
    {
        if (! blank($userData['email_personal'] ?? null)) {
            return (string) $userData['email_personal'];
        }

        return $this->emailPorDefecto((string) ($userData['usuario'] ?? ''), $userData['empresa'] ?? null, $subsystem);
    }

    /**
     * Email por defecto de un login. Es el mismo que usa loginEnUso(), para que
     * lo que se comprueba y lo que se crea no diverjan.
     */
    private function emailPorDefecto(string $login, ?string $empresa, Subsystem $subsystem): string
    {
        $dominio = $subsystem->api_config['dominio'] ?? (($empresa ?: 'empresa').'.com.br');

        return strtolower($login.'@'.$dominio);
    }

    /**
     * Extrae la lista de elementos de una respuesta de Chatwoot, que según el
     * endpoint llega como lista directa o envuelta en `payload`/`data`.
     *
     * @return array<int, mixed>
     */
    private function itemsDe(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        $items = $json['payload'] ?? $json['data'] ?? $json;

        return is_array($items) ? $items : [];
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function contieneAgente(array $items, int|string $agentId): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['id'] ?? '') === (string) $agentId) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Asignación de agente a equipos
    // -----------------------------------------------------------------

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
                ['user_ids' => [$this->normalizarId($agentId)]],
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
                'user_ids' => [$this->normalizarId($agentId)],
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

    /**
     * Asigna el agente a todas sus cajas de entrada.
     *
     * Se intentan todas aunque una falle, para no dejar al agente sin acceso
     * a una inbox por un error puntual en otra.
     *
     * @param  array<int, int>  $inboxIds
     */
    private function asignarAInboxes(Subsystem $subsystem, int|string $accountId, array $inboxIds, int|string $agentId): SubsystemOperationResult
    {
        $fallos = [];

        foreach ($inboxIds as $inboxId) {
            $resultado = $this->asignarAInbox($subsystem, $accountId, $inboxId, $agentId);

            if (! $resultado->success) {
                $fallos[$inboxId] = $resultado->raw;
            }
        }

        if ($fallos !== []) {
            return SubsystemOperationResult::fail(
                'No se pudo añadir el agente a la caja de entrada de Chatwoot',
                $fallos,
            );
        }

        return SubsystemOperationResult::ok();
    }

    // -----------------------------------------------------------------
    // Desasociación: quitarle el acceso al agente
    // -----------------------------------------------------------------

    /**
     * Le quita el acceso al agente: lo desasocia de su caja de entrada y de
     * todos los equipos en los que estuviera.
     *
     * El agente **no** se borra (eso lo hace deleteUser()): aquí solo se
     * deshacen las asignaciones, que es lo que en Chatwoot equivale a "no
     * tener acceso", y es reversible con una reactivación.
     *
     * Antes de quitar nada se lee lo que el agente tiene realmente en Chatwoot
     * y se une con lo guardado en configuracion_adicional. Esa unión es la foto
     * que se devuelve para persistir (y la que usará la reactivación). Nunca se
     * reduce respecto a lo guardado: si un intento anterior ya quitó la inbox
     * pero falló en los equipos, la lectura en vivo ya no la vería, y sin esta
     * unión el reintento la perdería.
     */
    private function desactivarAgente(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $subsistema = $account->subsystem;

        if (! $subsistema instanceof Subsystem) {
            return SubsystemOperationResult::fail('La cuenta no tiene un subsistema válido en Chatwoot.');
        }

        if (blank($account->external_account_id)) {
            return SubsystemOperationResult::fail('La cuenta no tiene un ID externo de agente en Chatwoot.');
        }

        $accountId = $this->resolverAccountIdDeCuenta($account);
        $configuracion = $this->configuracionDe($account);
        $inboxesGuardadas = $this->inboxIdsDe($account, $configuracion);
        $teamsGuardados = $this->teamIdsDe($configuracion);
        $agentId = $account->external_account_id;

        $vivo = $this->leerAccesosEnVivo($subsistema, $accountId, $agentId, $inboxesGuardadas);

        // Si no se pudieron consultar los equipos y la cuenta no los tiene
        // guardados, no hay forma de saber de cuáles quitarlo: dar la
        // operación por buena dejaría al agente con acceso.
        if (! $vivo['equipos_consultados'] && $teamsGuardados === []) {
            return SubsystemOperationResult::fail('No se pudieron consultar los equipos de Chatwoot para quitarle el acceso al agente.');
        }

        $inboxSnapshot = $this->normalizarIds(array_merge($inboxesGuardadas, $vivo['inbox_ids']));
        $teamSnapshot = $this->normalizarIds(array_merge($teamsGuardados, $vivo['team_ids']));

        // Si la lectura en vivo fue incompleta, se intenta quitarlo también de
        // todos los equipos del account (los 404 se toleran). Esos ids extra
        // sirven para quitar el acceso, pero no entran en la foto.
        $teamsAQuitar = $vivo['completo']
            ? $teamSnapshot
            : $this->normalizarIds(array_merge($teamSnapshot, $vivo['todos_los_teams']));

        $fallos = [];

        $desasociacionInbox = $this->desasociarDeInboxes($subsistema, $accountId, $inboxSnapshot, $agentId);

        if (! $desasociacionInbox->success) {
            $fallos['inbox'] = $desasociacionInbox->raw;
        }

        $desasociacionEquipos = $this->desasociarDeEquipos($subsistema, $accountId, $agentId, $teamsAQuitar);

        if (! $desasociacionEquipos->success) {
            $fallos['teams'] = $desasociacionEquipos->raw;
        }

        if ($fallos !== []) {
            return SubsystemOperationResult::fail('No se pudo quitar el acceso al agente en Chatwoot', $fallos);
        }

        return SubsystemOperationResult::ok(
            raw: [
                'inbox_ids' => $inboxSnapshot,
                'team_ids' => $teamSnapshot,
            ],
            configuracionAdicional: array_merge($configuracion, [
                'account_id' => $accountId,
                'inbox_ids' => $inboxSnapshot,
                'team_ids' => $teamSnapshot,
                'inbox_asignado' => false,
                'teams_asignados' => false,
            ]),
        );
    }

    /**
     * Lee en Chatwoot a qué cajas de entrada y equipos pertenece el agente.
     *
     * Es una lectura de mejor esfuerzo: nunca lanza. Si algo falla, `completo`
     * queda en false y quien llama decide qué hacer con lo que sí se leyó.
     *
     * @param  array<int, int>  $inboxCandidatos  inboxes a comprobar (no hay forma barata de enumerarlas todas)
     * @return array{inbox_ids: array<int, int>, team_ids: array<int, int>, todos_los_teams: array<int, int>, equipos_consultados: bool, completo: bool}
     */
    private function leerAccesosEnVivo(Subsystem $subsystem, int|string $accountId, int|string $agentId, array $inboxCandidatos): array
    {
        $resultado = [
            'inbox_ids' => [],
            'team_ids' => [],
            'todos_los_teams' => [],
            'equipos_consultados' => false,
            'completo' => true,
        ];

        foreach ($inboxCandidatos as $inboxId) {
            try {
                $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/inbox_members/{$inboxId}");

                if ($response->failed()) {
                    $resultado['completo'] = false;

                    continue;
                }

                if ($this->contieneAgente($this->itemsDe($response->json()), $agentId)) {
                    $resultado['inbox_ids'][] = (int) $inboxId;
                }
            } catch (Throwable $exception) {
                $this->log('No se pudo leer la pertenencia del agente a una inbox de Chatwoot', [
                    'inbox_id' => $inboxId,
                    'error' => $exception->getMessage(),
                ]);

                $resultado['completo'] = false;
            }
        }

        try {
            $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/teams");

            if ($response->failed()) {
                $resultado['completo'] = false;

                return $resultado;
            }

            $equipos = $this->itemsDe($response->json());
            $resultado['equipos_consultados'] = true;

            foreach ($equipos as $equipo) {
                if (! is_array($equipo) || ! isset($equipo['id'])) {
                    continue;
                }

                $teamId = (int) $equipo['id'];
                $resultado['todos_los_teams'][] = $teamId;

                $miembros = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/teams/{$teamId}/team_members");

                if ($miembros->failed()) {
                    $resultado['completo'] = false;

                    continue;
                }

                if ($this->contieneAgente($this->itemsDe($miembros->json()), $agentId)) {
                    $resultado['team_ids'][] = $teamId;
                }
            }
        } catch (Throwable $exception) {
            $this->log('No se pudo leer la pertenencia del agente a los equipos de Chatwoot', [
                'error' => $exception->getMessage(),
            ]);

            $resultado['completo'] = false;
        }

        return $resultado;
    }

    /**
     * @param  array<int, int>  $inboxIds
     */
    private function desasociarDeInboxes(Subsystem $subsystem, int|string $accountId, array $inboxIds, int|string $agentId): SubsystemOperationResult
    {
        $fallos = [];

        foreach ($inboxIds as $inboxId) {
            $response = $this->http($subsystem)->delete(
                "/api/v1/accounts/{$accountId}/inbox_members",
                [
                    'inbox_id' => (int) $inboxId,
                    'user_ids' => [$this->normalizarId($agentId)],
                ],
            );

            // 404/410: el agente ya no estaba en esa inbox. El objetivo —que
            // no tenga acceso— se cumple igual, así que no cuenta como fallo.
            if ($response->failed() && ! in_array($response->status(), [404, 410], true)) {
                $fallos[$inboxId] = $response->json() ?? ['status' => $response->status()];
            }
        }

        if ($fallos !== []) {
            return SubsystemOperationResult::fail(
                'No se pudo quitar el agente de la caja de entrada de Chatwoot',
                $fallos,
            );
        }

        return SubsystemOperationResult::ok();
    }

    /**
     * @param  array<int, int>  $teamIds
     */
    private function desasociarDeEquipos(Subsystem $subsystem, int|string $accountId, int|string $agentId, array $teamIds): SubsystemOperationResult
    {
        $fallos = [];

        foreach ($teamIds as $teamId) {
            $response = $this->http($subsystem)->delete(
                "/api/v1/accounts/{$accountId}/teams/{$teamId}/team_members",
                ['user_ids' => [$this->normalizarId($agentId)]],
            );

            if ($response->failed() && ! in_array($response->status(), [404, 410], true)) {
                $fallos[$teamId] = $response->json() ?? ['status' => $response->status()];
            }
        }

        if ($fallos !== []) {
            return SubsystemOperationResult::fail(
                'No se pudo quitar el agente de uno o más equipos de Chatwoot',
                $fallos,
            );
        }

        return SubsystemOperationResult::ok();
    }

    /**
     * Devuelve al agente a la caja de entrada y a los equipos que tenía
     * guardados en la cuenta.
     */
    private function asignarAccesos(UserSubsystemAccount $account, int|string $accountId, int|string $agentId): SubsystemOperationResult
    {
        $subsistema = $account->subsystem;
        $configuracion = $this->configuracionDe($account);
        $inboxIds = $this->inboxIdsDe($account, $configuracion);
        $teamIds = $this->teamIdsDe($configuracion);

        if ($inboxIds === []) {
            return SubsystemOperationResult::fail('No hay ninguna caja de entrada configurada para esta empresa en Chatwoot');
        }

        $fallos = [];

        $asignacionInbox = $this->asignarAInboxes($subsistema, $accountId, $inboxIds, $agentId);

        if (! $asignacionInbox->success) {
            $fallos['inbox'] = $asignacionInbox->raw;
        }

        $asignacionEquipos = $this->asignarAEquipos($subsistema, $accountId, $agentId, $teamIds);

        if (! $asignacionEquipos->success) {
            $fallos['teams'] = $asignacionEquipos->raw;
        }

        if ($fallos !== []) {
            return SubsystemOperationResult::fail('No se pudo devolver el acceso al agente en Chatwoot', $fallos);
        }

        return SubsystemOperationResult::ok(
            externalAccountId: (string) $agentId,
            estado: 'activo',
            mensaje: 'Agente reactivado en Chatwoot.',
            raw: [
                'inbox_ids' => $inboxIds,
                'team_ids' => $teamIds,
            ],
            configuracionAdicional: array_merge($configuracion, [
                'account_id' => $accountId,
                'inbox_ids' => $inboxIds,
                'team_ids' => $teamIds,
                'inbox_asignado' => true,
                'teams_asignados' => true,
            ]),
        );
    }

    /**
     * Crea el agente de nuevo —el anterior ya no existe en Chatwoot— y le
     * asigna los mismos accesos que tenía guardados en la cuenta.
     *
     * Si el agente se crea pero no se le pueden asignar la inbox o los equipos,
     * se devuelve fallo (con su id en raw): un agente activo sin accesos es una
     * cuenta rota. El reintento lo reencuentra por email (ver buscarAgente()) y
     * solo repite la asignación, sin duplicarlo.
     */
    private function crearAgente(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $usuario = $account->user;

        if ($usuario === null) {
            return SubsystemOperationResult::fail('La cuenta de Chatwoot no tiene un usuario gestionado asociado');
        }

        $configuracion = $this->configuracionDe($account);
        $inboxIds = $this->inboxIdsDe($account, $configuracion);

        if ($inboxIds === []) {
            return SubsystemOperationResult::fail('No hay ninguna caja de entrada configurada para esta empresa en Chatwoot');
        }

        $resultado = $this->createUser([
            'nombre_completo' => $usuario->nombre_completo,
            'usuario' => $usuario->usuario,
            'email_personal' => $usuario->email_personal,
            'empresa' => $usuario->empresa,
            // El agente se crea desde cero: sin esto perdería los equipos y la
            // caja de entrada que tenía antes, que están guardados en la
            // propia cuenta.
            'subsystem_config' => [
                'chatwoot' => [
                    'teams' => $this->teamIdsDe($configuracion),
                    'inbox_ids' => $inboxIds,
                ],
            ],
        ], $account->subsystem);

        if (! $resultado->success) {
            return $resultado;
        }

        $creada = $resultado->configuracionAdicional;

        if (($creada['inbox_asignado'] ?? false) !== true || ($creada['teams_asignados'] ?? false) !== true) {
            return SubsystemOperationResult::fail(
                $resultado->mensaje ?? 'Agente recreado en Chatwoot, pero no se le pudieron asignar todos sus accesos.',
                array_merge($resultado->raw ?? [], ['agent_id' => $resultado->externalAccountId]),
            );
        }

        return SubsystemOperationResult::ok(
            externalAccountId: $resultado->externalAccountId,
            estado: 'activo',
            mensaje: 'Agente recreado en Chatwoot con sus mismos accesos.',
            raw: $resultado->raw,
            configuracionAdicional: array_merge($configuracion, $creada),
        );
    }

    // -----------------------------------------------------------------
    // Configuración guardada de la cuenta (cajas de entrada y equipos)
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function configuracionDe(UserSubsystemAccount $account): array
    {
        $configuracion = $account->configuracion_adicional;

        return is_array($configuracion) ? $configuracion : [];
    }

    /**
     * Cajas de entrada a las que el agente tenía acceso.
     *
     * Lo normal es que estén guardadas en configuracion_adicional (es lo que
     * deja registrado el alta). Las cuentas creadas antes de que ese campo
     * existieran no lo tienen, y para ellas se usa la inbox configurada para
     * la empresa.
     *
     * @param  array<string, mixed>  $configuracion
     * @return array<int, int>
     */
    private function inboxIdsDe(UserSubsystemAccount $account, array $configuracion): array
    {
        $guardadas = $configuracion['inbox_ids'] ?? [];

        if (is_array($guardadas) && $guardadas !== []) {
            return $this->normalizarIds($guardadas);
        }

        try {
            return [(int) $this->resolverInboxId($account->subsystem, $account->user?->empresa)];
        } catch (Throwable $exception) {
            $this->log('No se pudo resolver la caja de entrada de Chatwoot de la cuenta', [
                'cuenta_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $configuracion
     * @return array<int, int>
     */
    private function teamIdsDe(array $configuracion): array
    {
        $guardados = $configuracion['team_ids'] ?? [];

        return is_array($guardados) ? $this->normalizarIds($guardados) : [];
    }

    /**
     * Cajas de entrada a las que se asigna el agente en el alta.
     *
     * Lo normal es la inbox configurada para la empresa en api_config.inboxes.
     * La reactivación de Chatwoot usa la que tenía guardada la cuenta, que es
     * la que la desactivación le quitó.
     *
     * @return array<int, int>
     */
    private function resolverInboxIdsDeAlta(array $userData, Subsystem $subsystem): array
    {
        $guardadas = $userData['subsystem_config']['chatwoot']['inbox_ids']
            ?? $userData['subsystem_config'][$subsystem->slug]['inbox_ids']
            ?? $userData['subsystem_config']['inbox_ids']
            ?? [];

        if (is_array($guardadas) && $guardadas !== []) {
            return $this->normalizarIds($guardadas);
        }

        return [(int) $this->resolverInboxId($subsystem, $userData['empresa'] ?? null)];
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function normalizarIds(array $ids): array
    {
        $normalizados = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            array_filter($ids, static fn ($id): bool => is_scalar($id)),
        )));

        return array_values(array_filter($normalizados, static fn (int $id): bool => $id > 0));
    }

    /**
     * Los ids de Chatwoot son numéricos, pero external_account_id es una
     * columna de texto: solo se castea cuando el valor lo es, para no mandar
     * un 0 a la API.
     */
    private function normalizarId(int|string $id): int|string
    {
        return is_numeric($id) ? (int) $id : $id;
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
        } catch (Throwable $e) {
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
     * email por defecto que construye createUser() (emailPorDefecto()), porque
     * es contra ese valor contra el que se comprueba la unicidad.
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

        $email = $this->emailPorDefecto($login, $empresa, $subsystem);

        try {
            $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/agents", ['page' => 1]);
        } catch (Throwable $exception) {
            $this->log('No se pudo comprobar la disponibilidad del login en Chatwoot', [
                'login' => $login,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $agentes = $this->itemsDe($response->json());

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
