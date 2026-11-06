<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\Contracts\UsernameAvailabilityInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Microsoft Entra ID (Azure AD) vía Microsoft Graph API, multi-tenant.
 *
 * Un único Subsystem administra varios tenants y guarda en api_config.accounts:
 *   tenant_id, client_id, client_secret, dominio -> configuración por empresa
 *   timeout (opcional)                   -> segundos, por defecto 15
 *
 * cpf se mapea a employeeId; el login se construye como userPrincipalName.
 * Nota: PATCH en Graph devuelve 204 sin cuerpo -> no depender de $response->json().
 */
class EntraIdService extends BaseSubsystemService implements SubsystemConnectionInterface, UsernameAvailabilityInterface
{
    private const GRAPH_URL = 'https://graph.microsoft.com';

    private const LOGIN_URL = 'https://login.microsoftonline.com';

    // ---------------------------------------------------------------
    // Autenticación (client_credentials) y cliente HTTP
    // ---------------------------------------------------------------

    /**
     * Cliente HTTP hacia Graph con el token del tenant del subsistema.
     * Si Graph responde 401 (token vencido o revocado) renueva el token y reintenta una vez.
     */
    protected function http(Subsystem $subsystem, ?string $empresa = null): PendingRequest
    {
        [$config, $cuenta] = $this->configuracionDeEmpresa($subsystem, $empresa);

        return Http::baseUrl(self::GRAPH_URL)
            ->acceptJson()
            ->timeout((int) ($config['timeout'] ?? 15))
            ->withToken($this->accessToken($subsystem, $config, $cuenta))
            ->retry(2, 0, function ($exception, PendingRequest $request) use ($subsystem, $config, $cuenta) {
                if (! $exception instanceof RequestException || $exception->response->status() !== 401) {
                    return false;
                }

                Cache::forget($this->tokenCacheKey($subsystem, $config));
                $request->withToken($this->accessToken($subsystem, $config, $cuenta));

                return true;
            }, throw: false);
    }

    private function accessToken(Subsystem $subsystem, array $config, ?string $cuenta): string
    {
        $key = $this->tokenCacheKey($subsystem, $config);

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $config = $this->requiredConfig($config, $cuenta);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) ($config['timeout'] ?? 15))
            ->post(self::LOGIN_URL.'/'.$config['tenant_id'].'/oauth2/v2.0/token', [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'scope' => self::GRAPH_URL.'/.default',
                'grant_type' => 'client_credentials',
            ]);

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            // No se incluye el cuerpo completo ni el secret en el mensaje.
            throw new RuntimeException(
                'Entra ID no pudo emitir el token (HTTP '.$response->status().': '.$response->json('error', 'sin_detalle').')',
            );
        }

        // Se renueva 60s antes del vencimiento real.
        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    private function tokenCacheKey(Subsystem $subsystem, array $config): string
    {
        return "entraid:token:{$subsystem->id}:".($config['tenant_id'] ?? 'default');
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredConfig(array $config, ?string $cuenta = null): array
    {
        $faltantes = array_filter(
            ['tenant_id', 'client_id', 'client_secret'],
            fn (string $clave) => empty($config[$clave]),
        );

        if ($faltantes !== []) {
            $contexto = $cuenta ? " para la empresa '{$cuenta}'" : '';
            throw new RuntimeException('Falta configuración de Entra ID'.$contexto.': '.implode(', ', $faltantes));
        }

        return $config;
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function configuracionDeEmpresa(Subsystem $subsystem, ?string $empresa): array
    {
        $config = $subsystem->api_config ?? [];
        $cuentas = $config['accounts'] ?? [];

        if ($cuentas === []) {
            return [$config, null];
        }

        $empresaNormalizada = $this->normalizarEmpresa($empresa);

        foreach ($cuentas as $cuenta => $configuracion) {
            if ($this->normalizarEmpresa((string) $cuenta) !== $empresaNormalizada) {
                continue;
            }

            if (! is_array($configuracion)) {
                throw new RuntimeException("La configuración de Entra ID para '{$cuenta}' debe ser un objeto");
            }

            return [array_merge($config, $configuracion), (string) $cuenta];
        }

        throw new RuntimeException(
            "No hay configuración de Entra ID para la empresa '{$empresa}'. Empresas configuradas: ".implode(', ', array_keys($cuentas)),
        );
    }

    private function normalizarEmpresa(?string $empresa): string
    {
        return strtolower(trim((string) $empresa));
    }

    private function mapaCuentas(Subsystem $subsystem): array
    {
        return $subsystem->api_config['accounts'] ?? [];
    }

    // ---------------------------------------------------------------
    // Operaciones
    // ---------------------------------------------------------------

    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        $cuentas = $this->mapaCuentas($subsystem);
        $empresas = $cuentas === [] ? [null] : array_keys($cuentas);
        $resultados = [];

        foreach ($empresas as $empresa) {
            try {
                // /users necesita solo User.ReadWrite.All; /organization exigiría un permiso extra.
                $response = $this->http($subsystem, $empresa)->get('/v1.0/users', [
                    '$top' => 1,
                    '$select' => 'id',
                ]);
            } catch (Throwable $exception) {
                return SubsystemOperationResult::fail($exception->getMessage(), $resultados);
            }

            $resultados[$empresa ?? 'default'] = [
                'ok' => $response->successful(),
                'status' => $response->status(),
            ];

            if ($response->failed()) {
                return SubsystemOperationResult::fail(
                    'Entra ID no está disponible o rechazó la autenticación para '.($empresa ?? 'la configuración principal').' (HTTP '.$response->status().')',
                    $resultados,
                );
            }
        }

        return SubsystemOperationResult::ok(
            mensaje: 'Conexión y autenticación con Entra ID exitosas (todas las cuentas)',
            raw: $resultados,
        );
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $empresa = $userData['empresa'] ?? null;
        [$config] = $this->configuracionDeEmpresa($subsystem, $empresa);
        $dominio = $config['dominio'] ?? null;

        if (! $dominio) {
            return SubsystemOperationResult::fail('Falta api_config.dominio para armar el userPrincipalName');
        }

        $upn = $userData['usuario'].'@'.$dominio;

        // Idempotente: si ya existe un usuario con ese UPN, se reutiliza en vez de duplicar.
        $existente = $this->http($subsystem, $empresa)->get('/v1.0/users/'.rawurlencode($upn), [
            '$select' => 'id,accountEnabled,userPrincipalName',
        ]);

        if ($existente->successful()) {
            return SubsystemOperationResult::ok(
                credencialUsuario: $upn,
                externalAccountId: (string) $existente->json('id'),
                estado: $existente->json('accountEnabled') ? 'activo' : 'deshabilitado',
                raw: $existente->json() ?? [],
            );
        }

        if ($existente->status() !== 404) {
            return SubsystemOperationResult::fail(
                'No se pudo verificar si el usuario ya existe en Entra ID (HTTP '.$existente->status().')',
                $existente->json() ?? [],
            );
        }

        $payload = [
            'accountEnabled' => true,
            'displayName' => $userData['nombre_completo'],
            'mailNickname' => $userData['usuario'],
            'userPrincipalName' => $upn,
            'passwordProfile' => [
                'forceChangePasswordNextSignIn' => true,
                'password' => $userData['password_general'] ?? null,
            ],
        ];

        if (! empty($userData['cpf'])) {
            $payload['employeeId'] = $userData['cpf'];
        }

        $response = $this->http($subsystem, $empresa)->post('/v1.0/users', $payload);

        if ($response->failed()) {
            // Un usuario eliminado en Entra ID no aparece en /users (el GET
            // anterior devuelve 404), pero sigue en la papelera y su
            // userPrincipalName queda reservado. En ese caso se restaura y se
            // habilita en vez de devolver el "already exists" opaco de Graph.
            $eliminado = $this->buscarUsuarioEliminado(
                $subsystem,
                $empresa,
                $upn,
                isset($userData['usuario']) ? (string) $userData['usuario'] : null,
            );

            if ($eliminado !== null) {
                return $this->restaurarYHabilitarUsuario($subsystem, $empresa, $eliminado, $userData, $upn);
            }

            // Aunque no se pueda consultar la papelera (por permisos o por un
            // fallo puntual), el error de Graph sí identifica el UPN duplicado.
            // Se traduce a algo accionable en vez de dejar el mensaje crudo.
            $error = $response->json('error');
            $mensajeGraph = is_array($error) ? strtolower((string) ($error['message'] ?? '')) : '';

            if (str_contains($mensajeGraph, 'password must be specified')) {
                return SubsystemOperationResult::fail(
                    'No se pudo crear el usuario en Entra ID porque falta la contraseña general (password_general) para el alta.',
                    $response->json() ?? [],
                );
            }

            if (str_contains($mensajeGraph, 'already exists') || str_contains($mensajeGraph, 'ya existe')) {
                return SubsystemOperationResult::fail(
                    "No se pudo crear '{$upn}' en Entra ID porque ese userPrincipalName ya está reservado. "
                    .'Si el usuario está en la papelera de reciclaje, restáurelo o elimínelo '
                    .'permanentemente antes de volver a crear la cuenta.',
                    $response->json() ?? [],
                );
            }

            $detalle = is_array($error) ? trim((string) ($error['code'] ?? '').': '.(string) ($error['message'] ?? ''), ': ') : '';

            return SubsystemOperationResult::fail(
                'Entra ID rechazó la creación del usuario'.($detalle !== '' ? ': '.$detalle : ''),
                $response->json() ?? [],
            );
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: $upn,
            externalAccountId: (string) $response->json('id'),
            estado: 'activo',
            raw: $response->json() ?? [],
        );
    }

    /**
     * Restaura de la papelera al usuario cuyo UPN sigue reservado y lo deja
     * habilitado, reutilizando los datos del alta.
     *
     * @param  array<string, mixed>  $eliminado
     * @param  array<string, mixed>  $userData
     */
    private function restaurarYHabilitarUsuario(
        Subsystem $subsystem,
        ?string $empresa,
        array $eliminado,
        array $userData,
        string $upn,
    ): SubsystemOperationResult {
        $id = (string) ($eliminado['id'] ?? '');

        if ($id === '') {
            return SubsystemOperationResult::fail(
                "El usuario '{$upn}' está eliminado en Entra ID, pero no se pudo obtener su identificador para restaurarlo.",
                ['usuario_eliminado' => $eliminado],
            );
        }

        $restauracion = $this->http($subsystem, $empresa)
            ->post('/v1.0/directory/deletedItems/'.rawurlencode($id).'/restore');

        if ($restauracion->failed()) {
            return SubsystemOperationResult::fail(
                "El usuario '{$upn}' está eliminado en Entra ID y no se pudo restaurar "
                .'(HTTP '.$restauracion->status().'). Restáurelo manualmente o elimínelo '
                .'permanentemente antes de reintentar el alta.',
                array_merge($restauracion->json() ?? [], ['usuario_eliminado' => $eliminado]),
            );
        }

        $restaurado = $restauracion->json();
        $restaurado = is_array($restaurado) ? $restaurado : [];
        $idRestaurado = (string) ($restaurado['id'] ?? $id);

        // La respuesta del restore no siempre trae el estado. En ese caso se
        // consulta el usuario recién restaurado con el GET de siempre. Graph es
        // eventualmente consistente, así que se reintenta unos segundos.
        if (! array_key_exists('accountEnabled', $restaurado)) {
            $consultado = $this->obtenerUsuarioPorUpn($subsystem, $empresa, $upn);

            if ($consultado !== null) {
                $restaurado = array_merge($restaurado, $consultado);
                $idRestaurado = (string) ($restaurado['id'] ?? $idRestaurado);
            }
        }

        $estabaHabilitado = ($restaurado['accountEnabled'] ?? null) === true;

        // Solo se toca el usuario si estaba deshabilitado. Si el restore ya lo
        // devolvió habilitado, un PATCH inmediato puede responder 404 mientras
        // la restauración se propaga, y no hace falta.
        if (! $estabaHabilitado) {
            $payload = [
                'accountEnabled' => true,
                // Al eliminar un usuario, Entra ID antepone un identificador a
                // su UPN (ej. <guid>ana.silva@dominio). Se fija de nuevo el UPN
                // real del alta para que la cuenta conserve su credencial.
                'userPrincipalName' => $upn,
            ];

            if (! empty($userData['nombre_completo'])) {
                $payload['displayName'] = $userData['nombre_completo'];
            }

            if (! empty($userData['cpf'])) {
                $payload['employeeId'] = $userData['cpf'];
            }

            $habilitacion = $this->habilitarUsuarioRestaurado(
                $subsystem,
                $empresa,
                $idRestaurado,
                $payload,
            );

            if ($habilitacion === null || $habilitacion->failed()) {
                return SubsystemOperationResult::fail(
                    "El usuario '{$upn}' se restauró en Entra ID, pero no se pudo habilitar "
                    .'('.($habilitacion === null ? 'sin respuesta de Graph' : 'HTTP '.$habilitacion->status()).'). '
                    .'Habilítelo manualmente antes de reintentar el alta.',
                    array_merge($habilitacion?->json() ?? [], [
                        'usuario_eliminado' => $eliminado,
                        'usuario_restaurado' => $idRestaurado,
                    ]),
                );
            }

            $restaurado['accountEnabled'] = true;
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: (string) ($restaurado['userPrincipalName'] ?? $upn),
            externalAccountId: $idRestaurado,
            estado: 'activo',
            mensaje: $estabaHabilitado
                ? 'Usuario restaurado desde la papelera; ya estaba habilitado en Entra ID'
                : 'Usuario restaurado desde la papelera y habilitado en Entra ID',
            raw: array_merge($restaurado, [
                'usuario_eliminado' => $eliminado,
                'restaurado' => true,
            ]),
        );
    }

    /**
     * Consulta el usuario por su UPN mientras Graph termina de propagar la
     * restauración. Devuelve null si no aparece tras los reintentos.
     *
     * @return array<string, mixed>|null
     */
    private function obtenerUsuarioPorUpn(Subsystem $subsystem, ?string $empresa, string $upn): ?array
    {
        for ($intento = 1; $intento <= 4; $intento++) {
            try {
                $response = $this->http($subsystem, $empresa)->get(
                    '/v1.0/users/'.rawurlencode($upn),
                    ['$select' => 'id,accountEnabled,userPrincipalName,mailNickname,displayName'],
                );
            } catch (Throwable) {
                $response = null;
            }

            if ($response?->successful()) {
                $usuario = $response->json();

                return is_array($usuario) ? $usuario : null;
            }

            if ($intento < 4) {
                usleep(500_000);
            }
        }

        return null;
    }

    /**
     * Habilita al usuario restaurado. Si Graph todavía responde 404 por la
     * consistencia eventual del restore, se reintenta antes de darlo por
     * fallido.
     *
     * @param  array<string, mixed>  $payload
     */
    private function habilitarUsuarioRestaurado(
        Subsystem $subsystem,
        ?string $empresa,
        string $idRestaurado,
        array $payload,
    ): ?Response {
        $response = null;

        for ($intento = 1; $intento <= 3; $intento++) {
            try {
                $response = $this->http($subsystem, $empresa)
                    ->patch('/v1.0/users/'.rawurlencode($idRestaurado), $payload);
            } catch (Throwable) {
                $response = null;
            }

            if ($response !== null && ! $response->failed()) {
                return $response;
            }

            if ($response?->status() !== 404 || $intento === 3) {
                return $response;
            }

            usleep(500_000);
        }

        return $response;
    }

    /**
     * Busca en la papelera de reciclaje de Entra ID el usuario que corresponde
     * al alta. Devuelve null si no está eliminado o si la consulta no se puede
     * completar: en ese caso el llamante conserva el error original.
     *
     * No basta con filtrar por el UPN original: al eliminar un usuario, Entra
     * ID lo decora con un prefijo GUID (<guid>ana.silva@dominio), así que la
     * búsqueda exacta no lo encuentra. El mailNickname sí se conserva, y es el
     * mismo valor con el que se creó la cuenta (gestor_users.usuario).
     *
     * @return array<string, mixed>|null
     */
    private function buscarUsuarioEliminado(
        Subsystem $subsystem,
        ?string $empresa,
        string $upn,
        ?string $mailNickname = null,
    ): ?array {
        $filtros = ['userPrincipalName eq \''.str_replace("'", "''", $upn).'\''];

        if (filled($mailNickname)) {
            $filtros[] = 'mailNickname eq \''.str_replace("'", "''", $mailNickname).'\'';
        }

        foreach ($filtros as $filtro) {
            try {
                $response = $this->http($subsystem, $empresa)->get(
                    '/v1.0/directory/deletedItems/microsoft.graph.user',
                    [
                        '$filter' => $filtro,
                        '$select' => 'id,userPrincipalName,displayName,mailNickname,employeeId,deletedDateTime',
                    ],
                );
            } catch (Throwable) {
                continue;
            }

            if ($response->failed()) {
                continue;
            }

            $usuario = $response->json('value.0');

            if (is_array($usuario)) {
                return $usuario;
            }
        }

        return null;
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->patch('/v1.0/users/'.$account->external_account_id, [
            'accountEnabled' => false,
        ]);

        // PATCH exitoso en Graph devuelve 204 sin body.
        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo suspender el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'suspendido');
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->patch('/v1.0/users/'.$account->external_account_id, [
            'accountEnabled' => true,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo');
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->patch('/v1.0/users/'.$account->external_account_id, [
            'accountEnabled' => false,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado');
    }

    public function supportsDeleteUser(): bool
    {
        return true;
    }

    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)
            ->delete('/v1.0/users/'.rawurlencode((string) $account->external_account_id));

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo eliminar el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'eliminado');
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->get('/v1.0/users/'.$account->external_account_id, [
            '$select' => 'accountEnabled',
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            estado: $response->json('accountEnabled') ? 'activo' : 'deshabilitado',
            raw: $response->json() ?? [],
        );
    }

    /**
     * Graph es eventualmente consistente: el GET que sigue al PATCH puede
     * devolver todavía el accountEnabled anterior. Con una sola lectura, una
     * baja que sí deshabilitó al usuario se reportaba como no confirmada, así
     * que se le da a la confirmación unos segundos de margen.
     */
    public function intentosConfirmacionEstado(): int
    {
        return 4;
    }

    public function retardoConfirmacionEstadoMs(): int
    {
        return 1000;
    }

    /**
     * Restablece la contraseña de un usuario en Entra ID.
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->patch('/v1.0/users/'.$account->external_account_id, [
            'passwordProfile' => [
                'forceChangePasswordNextSignIn' => true,
                'password' => $newPassword,
            ],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo restablecer la contraseña en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo');
    }

    public function supportsUpdateUser(): bool
    {
        return true;
    }

    /**
     * Solo atributos de contacto. El userPrincipalName y el mailNickname son la
     * clave con la que se creó la cuenta, así que no se tocan: renombrarlos en
     * Entra ID es otra operación y no un cambio de datos.
     */
    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult
    {
        $payload = ['displayName' => $userData['nombre_completo']];

        if (! blank($userData['email_personal'] ?? null)) {
            $payload['mail'] = $userData['email_personal'];
        }

        $response = $this->http($account->subsystem, $account->user->empresa ?? null)
            ->patch('/v1.0/users/'.$account->external_account_id, $payload);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudieron actualizar los datos del usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    /**
     * El userPrincipalName es la identidad de la cuenta, así que se consulta
     * exactamente el UPN que crearía createUser(): mismo dominio, misma
     * resolución de configuración por empresa.
     *
     * 404 = libre, que es el mismo criterio que usa createUser() para no
     * duplicar. Cualquier otro fallo devuelve null.
     */
    public function loginEnUso(string $login, ?string $empresa, Subsystem $subsystem): ?bool
    {
        try {
            [$config] = $this->configuracionDeEmpresa($subsystem, $empresa);
        } catch (RuntimeException $exception) {
            $this->log('No se pudo resolver la configuración de Entra ID para comprobar el login', [
                'login' => $login,
                'empresa' => $empresa,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $dominio = $config['dominio'] ?? null;

        if (blank($dominio)) {
            return null;
        }

        try {
            $response = $this->http($subsystem, $empresa)->get(
                '/v1.0/users/'.rawurlencode($login.'@'.$dominio),
                ['$select' => 'id'],
            );
        } catch (Throwable $exception) {
            $this->log('No se pudo comprobar la disponibilidad del login en Entra ID', [
                'login' => $login,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->status() === 404) {
            return false;
        }

        if ($response->failed()) {
            return null;
        }

        return true;
    }
}
