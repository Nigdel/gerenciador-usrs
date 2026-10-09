<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\ActorContext;
use Illuminate\Http\Request;

/**
 * Registro de acciones administrativas.
 *
 * El objetivo es que se pueda reconstruir quién cambió qué y desde dónde, sin
 * que la propia bitácora se convierta en el sitio donde acaban las
 * credenciales. Por eso los payloads pasan SIEMPRE por `limpiar()`: el
 * `payload` es libre y quien llama podría mandar a auditar lo que le dé la gana,
 * así que el filtro va aquí y no en cada punto de llamada. Quitarlo de un
 * sitio concreto y no de otro sería confiar en que nadie lo olvide.
 */
class AuditService
{
    /*
    |--------------------------------------------------------------------------
    | Acciones registradas
    |--------------------------------------------------------------------------
    |
    | Nombres en `recurso.accion`, en minúsculas y con punto. El prefijo dice
    | qué se tocó y el verbo qué se le hizo, que es como se busca después en un
    | log. El login fallido es el único que no encaja: no hay recurso al que
    | atribuirlo, porque el usuario todavía no existe.
    */

    public const LOGIN_FALLIDO = 'auth.login.failed';

    public const USUARIO_CREADO = 'gestor_usuario.creado';

    public const USUARIO_ACTUALIZADO = 'gestor_usuario.actualizado';

    public const USUARIO_ELIMINADO = 'gestor_usuario.eliminado';

    public const USUARIO_SUSPENDIDO = 'gestor_usuario.suspendido';

    public const USUARIO_DADO_DE_BAJA = 'gestor_usuario.dado_de_baja';

    public const USUARIO_REACTIVADO = 'gestor_usuario.reactivado';

    public const USUARIO_SINCRONIZADO = 'gestor_usuario.subsistemas_sincronizados';

    public const USUARIO_CONTRASENA_RESTABLECIDA = 'gestor_usuario.contrasena_restablecida';

    public const OPERACION_REINTENTADA = 'operacion.reintentada';

    public const OPERADOR_CREADO = 'operador.creado';

    public const OPERADOR_ACTUALIZADO = 'operador.actualizado';

    public const OPERADOR_ELIMINADO = 'operador.eliminado';

    public const SUBSISTEMA_CREADO = 'subsistema.creado';

    public const SUBSISTEMA_ACTUALIZADO = 'subsistema.actualizado';

    public const SUBSISTEMA_ELIMINADO = 'subsistema.eliminado';

    public const SUBSISTEMA_CONEXION_PROBADA = 'subsistema.conexion_probada';

    public const CUENTA_ACCION = 'cuenta.accion';

    /*
    |--------------------------------------------------------------------------
    | Claves que nunca se guardan
    |--------------------------------------------------------------------------
    |
    | Se comparan en minúsculas y buscando también dentro del nombre, porque
    | el dato sensible no siempre llega con el nombre obvio: la configuración
    | de un subsistema entra como `api_config` y dentro van `token`,
    | `api_key` o `secret`. Ocultar solo las claves exactas dejaría el
    | `api_config` entero en la bitácora, que es justo el campo con todos los
    | secretos de un subsistema.
    |
    | Los valores se sustituyen por un marcador en vez de por null: queda
    | constancia de que el campo venía informado —que a veces ya es relevante—
    | sin guardar el valor.
    */

    private const MARCA = '[oculto]';

    /** @var list<string> */
    private const CLAVES_SENSIBLES = [
        'password',
        'password_general',
        'contrasena',
        'contrasena_temporal',
        'secret',
        'token',
        'api_key',
        'apikey',
        'api_config',
        'authorization',
        // Solo con 'auth' se cubren `X-Auth` y `auth_token`, que es como la
        // van llamando las cabeceras y las configuraciones reales.
        'auth',
        'bearer',
        'credential',
        'credencial',
        'private_key',
        'client_secret',
    ];

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function log(string $action, ?array $payload = null, ?Request $request = null): void
    {
        $request ??= request();
        $actor = ActorContext::actual();

        AuditLog::create([
            // ActorContext y no Auth::id(): el mismo registro tiene que salir
            // igual por web, por API y por el scheduler, y el observer de
            // cuentas ya resuelve el actor por ahí.
            'user_id' => $actor?->id,
            'action' => $action,
            'origen' => ActorContext::origen(),
            'payload' => $payload === null ? null : $this->limpiar($payload),
            'ip_address' => $request->ip(),
            'user_agent' => $this->recortar($request->userAgent()),
            'performed_at' => now(),
        ]);
    }

    /**
     * Sustituye por el marcador todo valor cuya clave parezca sensible.
     *
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>
     */
    public function limpiar(array $datos): array
    {
        $salida = [];

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && $this->esSensible($clave)) {
                // Un null no es un secreto: dejarlo como está evita ensuciar la
                // bitácora con marcas de campos que siempre llegan vacíos.
                $salida[$clave] = $valor === null ? null : self::MARCA;

                continue;
            }

            $salida[$clave] = is_array($valor) ? $this->limpiar($valor) : $valor;
        }

        return $salida;
    }

    private function esSensible(string $clave): bool
    {
        $normalizada = mb_strtolower($clave);

        foreach (self::CLAVES_SENSIBLES as $sensible) {
            if (str_contains($normalizada, $sensible)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El user-agent lo manda el cliente, así que su tamaño no lo controla
     * nadie: sin tope, una petición con un UA de dos megabytes se guarda
     * entero en cada entrada del log.
     */
    private function recortar(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        return mb_substr($userAgent, 0, 255);
    }
}
