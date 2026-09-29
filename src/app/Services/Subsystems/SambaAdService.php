<?php

namespace App\Services\Subsystems;

use App\Contracts\IdentityProviderInterface;
use App\Contracts\SubsystemConnectionInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SambaAdService extends BaseSubsystemService implements IdentityProviderInterface, SubsystemConnectionInterface
{
    private const UAC_NORMAL_ACCOUNT = 512;
    private const UAC_DISABLED_ACCOUNT = 514; // 512 + ACCOUNTDISABLE (2)
    private const UAC_ACCOUNTDISABLE = 2;

    /**
     * Prueba la conexión y autenticación (Bind) con Samba AD.
     */
    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        $ldap = null;

        try {
            $ldap = $this->connectAndBind($subsystem);

            return SubsystemOperationResult::ok(mensaje: 'Autenticación contra Samba AD exitosa');
        } catch (Exception $e) {
            return SubsystemOperationResult::fail($e->getMessage());
        } finally {
            $this->unbind($ldap);
        }
    }

    /**
     * Busca un usuario por CPF (almacenado en employeeNumber).
     */
    public function findByCpf(string $cpf): ?array
    {
        $subsystem = $this->resolveSubsystem();
        $cpfLimpio = $this->limpiarDocumento($cpf);
        $ldap = null;

        try {
            $ldap = $this->connectAndBind($subsystem);
            $baseDn = $this->getUsersOu($subsystem);
            $filter = '(employeeNumber=' . $this->escapeFilter($cpfLimpio) . ')';

            $search = @ldap_search($ldap, $baseDn, $filter, ['cn', 'sAMAccountName', 'mail', 'employeeNumber', 'userAccountControl']);
            if (!$search) {
                return null;
            }

            $entries = @ldap_get_entries($ldap, $search);

            if (($entries['count'] ?? 0) === 0) {
                return null;
            }

            $user = $entries[0];

            return [
                'nombre_completo'     => $user['cn'][0] ?? null,
                'email_personal'      => $user['mail'][0] ?? null,
                'cpf'                 => $user['employeenumber'][0] ?? $cpfLimpio,
                'usuario'             => $user['samaccountname'][0] ?? null,
                'external_account_id' => $user['samaccountname'][0] ?? null,
            ];
        } catch (Exception $e) {
            Log::warning('SambaAD findByCpf falló', ['error' => $e->getMessage()]);

            return null;
        } finally {
            $this->unbind($ldap);
        }
    }

    /**
     * Verifica la existencia de un usuario por userPrincipalName o mail.
     */
    public function existsByEmail(string $email): bool
    {
        $subsystem = $this->resolveSubsystem();
        $cleanEmail = $this->escapeFilter(trim(strtolower($email)));
        $ldap = null;

        try {
            $ldap = $this->connectAndBind($subsystem);
            $baseDn = $this->getUsersOu($subsystem);
            $filter = "(|(userPrincipalName={$cleanEmail})(mail={$cleanEmail}))";

            $search = @ldap_search($ldap, $baseDn, $filter, ['dn']);
            if (!$search) {
                return false;
            }

            $entries = @ldap_get_entries($ldap, $search);

            return ($entries['count'] ?? 0) > 0;
        } catch (Exception $e) {
            Log::warning('SambaAD existsByEmail falló', ['error' => $e->getMessage()]);

            return false;
        } finally {
            $this->unbind($ldap);
        }
    }

    /**
     * Crea un nuevo usuario en la OU delegada.
     *
     * Flujo: add (cuenta deshabilitada) -> set unicodePwd -> habilitar (512).
     * Si algún paso posterior al add falla, se elimina la cuenta parcial.
     */
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $samAccountName = trim((string) ($userData['usuario'] ?? ''));
        $nombreCompleto = trim((string) ($userData['nombre_completo'] ?? ''));

        if ($samAccountName === '' || $nombreCompleto === '') {
            return SubsystemOperationResult::fail('Faltan datos obligatorios: usuario y nombre_completo');
        }

        if (strlen($samAccountName) > 20) {
            return SubsystemOperationResult::fail(
                "sAMAccountName '{$samAccountName}' excede el máximo de 20 caracteres de Active Directory"
            );
        }

        $usersOu = $this->getUsersOu($subsystem);
        // El RDN (CN) debe coincidir con el atributo cn.
        $userDn = 'CN=' . ldap_escape($nombreCompleto, '', LDAP_ESCAPE_DN) . ',' . $usersOu;
        $ldap = null;

        try {
            $ldap = $this->connectAndBind($subsystem);

            // Idempotencia: si ya existe por sAMAccountName, se reutiliza.
            $search = @ldap_search(
                $ldap,
                $usersOu,
                '(sAMAccountName=' . $this->escapeFilter($samAccountName) . ')',
                ['dn']
            );
            if ($search && (@ldap_get_entries($ldap, $search)['count'] ?? 0) > 0) {
                return SubsystemOperationResult::ok(
                    credencialUsuario: $samAccountName,
                    externalAccountId: $samAccountName,
                    estado: 'activo',
                    mensaje: 'El usuario ya existía en Samba AD, se reutilizó',
                );
            }

            $password = $userData['password_general'] ?? $this->generatePassword();
            $domain = $this->getDomain($subsystem);
            $upn = "{$samAccountName}@{$domain}";

            $entry = [
                'objectClass'        => ['top', 'person', 'organizationalPerson', 'user'],
                'sAMAccountName'     => $samAccountName,
                'userPrincipalName'  => $upn,
                'cn'                 => $nombreCompleto,
                'displayName'        => $nombreCompleto,
                // Se crea deshabilitada; se habilita al final tras fijar la contraseña.
                'userAccountControl' => (string) self::UAC_DISABLED_ACCOUNT,
            ];

            if (!empty($userData['email_personal'])) {
                $entry['mail'] = $userData['email_personal'];
            }

            if (!empty($userData['cpf'])) {
                $entry['employeeNumber'] = $this->limpiarDocumento($userData['cpf']);
            }

            // 1) Crear la cuenta
            if (!@ldap_add($ldap, $userDn, $entry)) {
                return SubsystemOperationResult::fail(
                    'Error al crear usuario en Samba AD: ' . $this->ldapError($ldap) . " (DN: {$userDn})"
                );
            }

            // 2) Fijar contraseña (requiere LDAPS y cumplir la política de complejidad)
            if (!@ldap_mod_replace($ldap, $userDn, ['unicodePwd' => $this->encodePassword($password)])) {
                $error = $this->ldapError($ldap);
                @ldap_delete($ldap, $userDn); // rollback

                return SubsystemOperationResult::fail(
                    "Usuario creado pero no se pudo fijar la contraseña (se revirtió la creación): {$error}"
                );
            }

            // 3) Habilitar la cuenta
            if (!@ldap_mod_replace($ldap, $userDn, ['userAccountControl' => (string) self::UAC_NORMAL_ACCOUNT])) {
                $error = $this->ldapError($ldap);
                @ldap_delete($ldap, $userDn); // rollback

                return SubsystemOperationResult::fail(
                    "No se pudo habilitar la cuenta (se revirtió la creación): {$error}"
                );
            }

            return SubsystemOperationResult::ok(
                credencialUsuario: $samAccountName,
                externalAccountId: $samAccountName,
                estado: 'activo',
                raw: ['dn' => $userDn, 'sAMAccountName' => $samAccountName, 'upn' => $upn]
            );
        } catch (Exception $e) {
            return SubsystemOperationResult::fail($e->getMessage());
        } finally {
            $this->unbind($ldap);
        }
    }

    /**
     * Resetea la contraseña utilizando la delegación Reset Password.
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        $ldap = null;

        try {
            $subsystem = $account->subsystem;
            $ldap = $this->connectAndBind($subsystem);
            $userDn = $this->resolveUserDn($ldap, $account, $subsystem);

            if (!@ldap_mod_replace($ldap, $userDn, ['unicodePwd' => $this->encodePassword($newPassword)])) {
                return SubsystemOperationResult::fail(
                    'No se pudo resetear la contraseña en Samba AD: ' . $this->ldapError($ldap)
                );
            }

            return SubsystemOperationResult::ok(mensaje: 'Contraseña reseteada exitosamente');
        } catch (Exception $e) {
            return SubsystemOperationResult::fail($e->getMessage());
        } finally {
            $this->unbind($ldap);
        }
    }

    /**
     * Habilita/Deshabilita usuarios via userAccountControl bitwise.
     */
    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        return $this->toggleUserAccountControl($account, disable: true);
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return $this->toggleUserAccountControl($account, disable: false);
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return $this->suspendUser($account, []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $ldap = null;

        try {
            $subsystem = $account->subsystem;
            $ldap = $this->connectAndBind($subsystem);
            $userDn = $this->resolveUserDn($ldap, $account, $subsystem);

            $search = @ldap_read($ldap, $userDn, '(objectClass=*)', ['userAccountControl']);
            if (!$search) {
                return SubsystemOperationResult::fail('Usuario no encontrado en Samba AD');
            }

            $entries = @ldap_get_entries($ldap, $search);

            $uac = (int) ($entries[0]['useraccountcontrol'][0] ?? self::UAC_NORMAL_ACCOUNT);
            $isDisabled = ($uac & self::UAC_ACCOUNTDISABLE) === self::UAC_ACCOUNTDISABLE;

            return SubsystemOperationResult::ok(
                estado: $isDisabled ? 'suspendido' : 'activo',
                raw: ['userAccountControl' => $uac]
            );
        } catch (Exception $e) {
            return SubsystemOperationResult::fail($e->getMessage());
        } finally {
            $this->unbind($ldap);
        }
    }

    public function supportsDeleteUser(): bool
    {
        return true;
    }

    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $ldap = null;

        try {
            $subsystem = $account->subsystem;
            $ldap = $this->connectAndBind($subsystem);
            $userDn = $this->resolveUserDn($ldap, $account, $subsystem);

            if (!@ldap_delete($ldap, $userDn)) {
                return SubsystemOperationResult::fail(
                    'No se pudo eliminar el usuario en Samba AD: ' . $this->ldapError($ldap)
                );
            }

            return SubsystemOperationResult::ok(estado: 'eliminado');
        } catch (Exception $e) {
            return SubsystemOperationResult::fail($e->getMessage());
        } finally {
            $this->unbind($ldap);
        }
    }

    // -----------------------------------------------------------------
    // Métodos Internos LDAP
    // -----------------------------------------------------------------

    /**
     * @return \LDAP\Connection
     */
    private function connectAndBind(Subsystem $subsystem)
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('La extensión PHP ldap no está cargada en este proceso (revisa CLI vs php-fpm).');
        }

        $config = $subsystem->api_config ?? [];

        // trim() elimina espacios, \r y \n que rompen la URI (típico de .env con CRLF).
        $host = trim((string) ($config['host'] ?? config('services.samba_ad.host', 'dc1.klios.br')));
        $port = (int) ($config['port'] ?? config('services.samba_ad.port', 636));
        $useLdaps = filter_var(
            $config['use_ldaps'] ?? config('services.samba_ad.use_ldaps', true),
            FILTER_VALIDATE_BOOLEAN
        );
        $tlsVerify = filter_var(
            $config['tls_verify'] ?? config('services.samba_ad.tls_verify', true),
            FILTER_VALIDATE_BOOLEAN
        );
        $caCertPath = trim((string) ($config['ca_cert_path'] ?? ''));

        if ($host === '') {
            throw new RuntimeException('Host de Samba AD vacío en api_config (ejecuta el SubsystemSeeder).');
        }

        $protocol = $useLdaps ? 'ldaps://' : 'ldap://';
        $ldapUri = "{$protocol}{$host}:{$port}";

        // Opciones TLS globales: deben fijarse ANTES de ldap_connect().
        if (!$tlsVerify) {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        } else {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);

            if ($caCertPath !== '') {
                if (!is_readable($caCertPath)) {
                    throw new RuntimeException(
                        "El certificado CA '{$caCertPath}' no existe o no es legible por el usuario del proceso PHP."
                    );
                }
                ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $caCertPath);
            }
        }

                // Aplica las opciones TLS a un contexto nuevo (necesario en procesos long-running como Octane/queue).
        /*         if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
                    @ldap_set_option(null, LDAP_OPT_X_TLS_NEWCTX, 0);
                } */

        $ldap = @ldap_connect($ldapUri);
        if (!$ldap) {
            throw new RuntimeException(
                "No se pudo conectar al DC Active Directory: {$host} (URI: '{$ldapUri}', longitud: " . strlen($ldapUri) . ')'
            );
        }

        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 10);

        $bindDn = trim((string) ($config['bind_dn'] ?? config('services.samba_ad.bind_dn')));
        $bindPassword = (string) ($config['bind_password'] ?? config('services.samba_ad.bind_password'));

        if (!@ldap_bind($ldap, $bindDn, $bindPassword)) {
            $error = $this->ldapError($ldap);
            $this->unbind($ldap);

            throw new RuntimeException("Fallo de autenticación Bind ({$bindDn}) contra {$ldapUri}: {$error}");
        }

        return $ldap;
    }

    private function toggleUserAccountControl(UserSubsystemAccount $account, bool $disable): SubsystemOperationResult
    {
        $ldap = null;

        try {
            $subsystem = $account->subsystem;
            $ldap = $this->connectAndBind($subsystem);
            $userDn = $this->resolveUserDn($ldap, $account, $subsystem);

            $search = @ldap_read($ldap, $userDn, '(objectClass=*)', ['userAccountControl']);
            if (!$search) {
                return SubsystemOperationResult::fail('Usuario no encontrado');
            }

            $entries = @ldap_get_entries($ldap, $search);
            $currentUac = (int) ($entries[0]['useraccountcontrol'][0] ?? self::UAC_NORMAL_ACCOUNT);

            $newUac = $disable
                ? ($currentUac | self::UAC_ACCOUNTDISABLE)
                : ($currentUac & ~self::UAC_ACCOUNTDISABLE);

            if (!@ldap_mod_replace($ldap, $userDn, ['userAccountControl' => (string) $newUac])) {
                return SubsystemOperationResult::fail(
                    'Error al actualizar userAccountControl: ' . $this->ldapError($ldap)
                );
            }

            return SubsystemOperationResult::ok(estado: $disable ? 'suspendido' : 'activo');
        } catch (Exception $e) {
            return SubsystemOperationResult::fail($e->getMessage());
        } finally {
            $this->unbind($ldap);
        }
    }

    /**
     * Resuelve el DN real buscando por sAMAccountName (el CN ya no es el sAMAccountName).
     */
    private function resolveUserDn($ldap, UserSubsystemAccount $account, Subsystem $subsystem): string
    {
        $samAccountName = (string) $account->external_account_id;
        $usersOu = $this->getUsersOu($subsystem);

        $search = @ldap_search(
            $ldap,
            $usersOu,
            '(sAMAccountName=' . $this->escapeFilter($samAccountName) . ')',
            ['dn']
        );

        if ($search) {
            $entries = @ldap_get_entries($ldap, $search);
            if (($entries['count'] ?? 0) > 0) {
                return $entries[0]['dn'];
            }
        }

        throw new RuntimeException("Usuario '{$samAccountName}' no encontrado en {$usersOu}");
    }

    private function ldapError($ldap): string
    {
        $errno = ldap_errno($ldap);
        $error = ldap_error($ldap);
        $diagnostic = '';
        @ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);

        $message = "[{$errno}] {$error}";
        if (!empty($diagnostic)) {
            $message .= " - {$diagnostic}";
        }

        return $message;
    }

    private function unbind($ldap): void
    {
        if ($ldap) {
            @ldap_unbind($ldap);
        }
    }

    private function escapeFilter(string $value): string
    {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }

    private function encodePassword(string $password): string
    {
        return iconv('UTF-8', 'UTF-16LE', '"' . $password . '"');
    }

    private function getUsersOu(Subsystem $subsystem): string
    {
        return trim((string) ($subsystem->api_config['users_ou'] ?? config('services.samba_ad.users_ou', 'OU=Usuarios,DC=klios,DC=br')));
    }

    private function getDomain(Subsystem $subsystem): string
    {
        return trim((string) ($subsystem->api_config['dominio'] ?? config('services.samba_ad.dominio', 'klios.br')));
    }

    private function limpiarDocumento(string $documento): string
    {
        return preg_replace('/\D/', '', $documento) ?? '';
    }

    private function resolveSubsystem(): Subsystem
    {
        return Subsystem::where('slug', 'sambaad')->firstOrFail();
    }

    private function generatePassword(): string
    {
        return 'Klios#' . bin2hex(random_bytes(4)) . '!';
    }
}