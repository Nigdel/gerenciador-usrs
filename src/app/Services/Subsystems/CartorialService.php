<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cartorial (Klios) — gestión del cartorial de un usuario.
 *
 * Usa la MISMA autenticación y configuración que AdagioService:
 *  - Login: POST {api_url}/kliosAnalise/login (multipart email/password).
 *  - Credenciales y timeout en $subsystem->api_config (email, password, timeout).
 *  - El token se guarda en la MISMA clave de caché que AdagioService
 *    ("adagio:token:{id}"), de modo que ambos servicios comparten sesión.
 *  - Reintento único de login ante 401/403.
 *
 * A diferencia de Adagio (multipart), la documentación de la API Cartorial
 * especifica cuerpos JSON, por lo que aquí se envía JSON.
 *
 * Endpoints:
 *  - POST /proprietarios/internos                                  crear
 *  - PUT  /proprietarios/internos/{id}                             actualizar
 *  - PUT  /proprietarios/internos/{id}/inativar                    inactivar
 *  - POST /proprietarios/internos/{id}/enviarDocumento             enviar documento
 */
class CartorialService extends BaseSubsystemService implements SubsystemConnectionInterface
{
    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        Cache::forget($this->tokenCacheKey($subsystem));

        try {
            $this->token($subsystem);
        } catch (RuntimeException $exception) {
            return SubsystemOperationResult::fail($exception->getMessage());
        }

        return SubsystemOperationResult::ok(mensaje: 'Autenticación contra Cartorial exitosa');
    }

    /**
     * Crea un usuario con su cartorial.
     *
     * $data: contexto_id, nome, documento, email, password, enviar_cartorial (bool),
     *        cartorial => [operacao, contratacao (Y-m-d), nome_condutor, rg_condutor,
     *        cpf_condutor, nascimento_condutor (Y-m-d), funcao, uf, celular, telefone]
     */
    public function crearCartorial(array $data, ?Subsystem $subsystem = null): SubsystemOperationResult
    {
        $subsystem ??= $this->resolveSubsystem();

        $payload = [
            'contexto_id' => $data['contexto_id'] ?? null,
            'nome' => $data['nome'] ?? null,
            'documento' => $this->limpiarDocumento((string) ($data['documento'] ?? '')),
            'email' => $data['email'] ?? null,
            'password' => $data['password'] ?? null,
            'enviar_cartorial' => (bool) ($data['enviar_cartorial'] ?? true),
            'cartorial' => $data['cartorial'] ?? [],
        ];

        if (isset($payload['cartorial']['cpf_condutor'])) {
            $payload['cartorial']['cpf_condutor'] = $this->limpiarDocumento((string) $payload['cartorial']['cpf_condutor']);
        }

        $payload = $this->sinNulos($payload);

        $result = $this->ejecutar($subsystem, 'POST', 'proprietarios/internos', $payload, 'crear cartorial');

        if (! $result['ok']) {
            return $result['fail'];
        }

        $usuario = $result['response']->json('usuario') ?? $result['response']->json() ?? [];

        return SubsystemOperationResult::ok(
            credencialUsuario: $usuario['email'] ?? ($payload['email'] ?? null),
            externalAccountId: isset($usuario['id']) ? (string) $usuario['id'] : null,
            estado: 'activo',
            mensaje: 'Cartorial creado',
            raw: $usuario,
        );
    }

    /**
     * Actualiza el cartorial.
     *
     * $data: departamento, colaborador_situacao_empresa,
     *        colaborador_afastamento_data_inicial (Y-m-d),
     *        colaborador_afastamento_data_final (Y-m-d)
     */
    public function actualizarCartorial(int|string $idCartorialDado, array $data, ?Subsystem $subsystem = null): SubsystemOperationResult
    {
        $subsystem ??= $this->resolveSubsystem();

        $payload = $this->sinNulos([
            'departamento' => $data['departamento'] ?? null,
            'colaborador_situacao_empresa' => $data['colaborador_situacao_empresa'] ?? null,
            'colaborador_afastamento_data_inicial' => $data['colaborador_afastamento_data_inicial'] ?? null,
            'colaborador_afastamento_data_final' => $data['colaborador_afastamento_data_final'] ?? null,
        ]);

        $result = $this->ejecutar(
            $subsystem,
            'PUT',
            "proprietarios/internos/{$idCartorialDado}",
            $payload,
            'actualizar cartorial',
        );

        if (! $result['ok']) {
            return $result['fail'];
        }

        return SubsystemOperationResult::ok(
            externalAccountId: (string) ($result['response']->json('id_cartorial_dado') ?? $idCartorialDado),
            mensaje: 'Cartorial actualizado',
            raw: $result['response']->json() ?? [],
        );
    }

    /**
     * Inactiva el cartorial. El motivo es opcional.
     */
    public function inactivarCartorial(
        int|string $idCartorialDado,
        int|string $usuarioId,
        ?string $motivo = null,
        ?Subsystem $subsystem = null,
    ): SubsystemOperationResult {
        $subsystem ??= $this->resolveSubsystem();

        $payload = $this->sinNulos([
            'usuario_id' => $usuarioId,
            'motivo' => filled($motivo) ? $motivo : null,
        ]);

        $result = $this->ejecutar(
            $subsystem,
            'PUT',
            "proprietarios/internos/{$idCartorialDado}/inativar",
            $payload,
            'inactivar cartorial',
        );

        if (! $result['ok']) {
            return $result['fail'];
        }

        return SubsystemOperationResult::ok(
            externalAccountId: (string) ($result['response']->json('id_cartorial_dado') ?? $idCartorialDado),
            estado: 'suspendido',
            mensaje: 'Cartorial inactivado',
            raw: $result['response']->json() ?? [],
        );
    }

    /**
     * Envía uno o varios documentos al cartorial.
     *
     * $documentos: lista de ['tipo' => int, 'base64' => string, 'data_vencimiento' => 'Y-m-d' (opcional)]
     */
    public function enviarDocumento(
        int|string $idCartorialDado,
        int|string $usuarioId,
        array $documentos,
        ?Subsystem $subsystem = null,
    ): SubsystemOperationResult {
        $subsystem ??= $this->resolveSubsystem();

        if ($documentos === []) {
            return SubsystemOperationResult::fail('Debe enviarse al menos un documento al cartorial');
        }

        $items = [];

        foreach ($documentos as $indice => $documento) {
            if (! isset($documento['tipo']) || blank($documento['base64'] ?? null)) {
                return SubsystemOperationResult::fail("El documento #{$indice} requiere 'tipo' y 'base64'");
            }

            // data_vencimiento es opcional: no todos los documentos vencen.
            $items[] = $this->sinNulos([
                'tipo' => $documento['tipo'],
                'data_vencimiento' => $documento['data_vencimiento'] ?? null,
                'base64' => $documento['base64'],
            ]);
        }

        $result = $this->ejecutar(
            $subsystem,
            'POST',
            "proprietarios/internos/{$idCartorialDado}/enviarDocumento",
            ['usuario_id' => $usuarioId, 'documentos' => $items],
            'enviar documento al cartorial',
        );

        if (! $result['ok']) {
            return $result['fail'];
        }

        return SubsystemOperationResult::ok(
            mensaje: (string) ($result['response']->json('msg') ?? 'Documento(s) enviado(s)'),
            raw: $result['response']->json() ?? [],
        );
    }

    // -----------------------------------------------------------------
    // SubsystemServiceInterface (contrato del orquestador)
    // -----------------------------------------------------------------

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('La creación de usuarios en Cartorial no está implementada.');
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('La suspensión de usuarios en Cartorial no está implementada.');
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('La reactivación de usuarios en Cartorial no está implementada.');
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('La deshabilitación de usuarios en Cartorial no está implementada.');
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('La consulta de estado en Cartorial no está implementada.');
    }

    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El restablecimiento de contraseña en Cartorial no está implementada.');
    }

    // -----------------------------------------------------------------
    // HTTP + autenticación (mismo login y caché que AdagioService)
    // -----------------------------------------------------------------

    /**
     * @return array{ok: bool, response?: Response, fail?: SubsystemOperationResult}
     */
    private function ejecutar(Subsystem $subsystem, string $method, string $endpoint, array $json, string $accion): array
    {
        try {
            $response = $this->request($subsystem, $method, $endpoint, $json);
        } catch (\Throwable $exception) {
            $this->log("Error de comunicación al {$accion}", ['error' => $exception->getMessage()]);

            return [
                'ok' => false,
                'fail' => SubsystemOperationResult::fail('Error de comunicación con Cartorial: '.$exception->getMessage()),
            ];
        }

        if ($response->failed()) {
            $this->log("Cartorial rechazó: {$accion}", [
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);

            $mensaje = match ($response->status()) {
                404 => "Cartorial no encontrado al {$accion}",
                422 => "Datos inválidos al {$accion}",
                default => "Cartorial respondió HTTP {$response->status()} al {$accion}",
            };

            return [
                'ok' => false,
                'fail' => SubsystemOperationResult::fail($mensaje, [
                    'http_status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]),
            ];
        }

        return ['ok' => true, 'response' => $response];
    }

    private function request(Subsystem $subsystem, string $method, string $endpoint, array $json): Response
    {
        $response = $this->send($subsystem, $method, $endpoint, $json, $this->token($subsystem));

        if (in_array($response->status(), [401, 403], true)) {
            // Token vencido o inválido: reautenticar una sola vez y reintentar.
            Cache::forget($this->tokenCacheKey($subsystem));
            $response = $this->send($subsystem, $method, $endpoint, $json, $this->token($subsystem));
        }

        return $response;
    }

    private function send(Subsystem $subsystem, string $method, string $endpoint, array $json, string $token): Response
    {
        $client = $this->cliente($subsystem, $token)->asJson();
        $url = trim($endpoint, '/');

        return match (strtoupper($method)) {
            'POST' => $client->post($url, $json),
            'PUT' => $client->put($url, $json),
            'GET' => $client->get($url, $json),
            default => throw new RuntimeException("Método HTTP no soportado por CartorialService: {$method}"),
        };
    }

    private function cliente(Subsystem $subsystem, string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $subsystem->api_url, '/').'/')
            ->timeout($subsystem->api_config['timeout'] ?? 30)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Cache-Control' => 'no-cache, private',
            ]);
    }

    private function token(Subsystem $subsystem): string
    {
        return Cache::remember($this->tokenCacheKey($subsystem), now()->addMinutes(15), function () use ($subsystem) {
            return $this->login($subsystem);
        });
    }

    private function login(Subsystem $subsystem): string
    {
        $email = $subsystem->api_config['email'] ?? null;
        $password = $subsystem->api_config['password'] ?? null;

        if (! $email || ! $password) {
            throw new RuntimeException('Falta api_config.email / api_config.password del subsistema');
        }

        $response = Http::baseUrl(rtrim((string) $subsystem->api_url, '/').'/')
            ->timeout($subsystem->api_config['timeout'] ?? 30)
            ->withHeaders(['Cache-Control' => 'no-cache, private'])
            ->attach('email', $email)
            ->attach('password', $password)
            ->post('kliosAnalise/login');

        if ($response->failed() || empty($response->json('token'))) {
            throw new RuntimeException('[Cartorial] No se pudo autenticar: '.$response->body());
        }

        return $response->json('token');
    }

    /** Misma clave que AdagioService: ambos servicios comparten el token. */
    private function tokenCacheKey(Subsystem $subsystem): string
    {
        return "adagio:token:{$subsystem->id}";
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function limpiarDocumento(string $documento): string
    {
        return preg_replace('/\D/', '', $documento) ?? '';
    }

    private function sinNulos(array $datos): array
    {
        return array_filter($datos, fn ($valor) => $valor !== null);
    }

    private function resolveSubsystem(): Subsystem
    {
        return Subsystem::proveedorIdentidad()->activos()->firstOrFail();
    }
}
