<?php

namespace App\Services\Import;

use App\Models\Subsystem;
use App\Services\Subsystems\SambaAdService;
use RuntimeException;

/**
 * Enumera las cuentas de Samba AD (LDAP).
 *
 * Extiende SambaAdService para reutilizar la conexión y el bind: la búsqueda
 * abarca la raíz del dominio; el CPF vive en employeeNumber y el estado en el
 * bit ACCOUNTDISABLE de userAccountControl (igual significado que el driver
 * usa al suspender/reactivar).
 */
class SambaAdRemoteAccountEnumerator extends SambaAdService implements RemoteAccountEnumerator
{
    private const UAC_ACCOUNTDISABLE = 2;

    public function slug(): string
    {
        return 'sambaad';
    }

    public function enumerar(Subsystem $subsystem): array
    {
        $ldap = null;

        try {
            $ldap = $this->connectAndBind($subsystem);

            $search = @ldap_search(
                $ldap,
                $this->getBaseDn($subsystem),
                '(&(objectCategory=person)(objectClass=user)(!(objectClass=computer)))',
                ['dn', 'cn', 'displayName', 'sAMAccountName', 'userPrincipalName', 'mail', 'employeeNumber', 'userAccountControl'],
            );

            if (! $search) {
                throw new RuntimeException(
                    'No se pudo buscar en Samba AD: '.$this->ldapError($ldap),
                );
            }

            $entries = @ldap_get_entries($ldap, $search);

            if ($entries === false) {
                throw new RuntimeException('No se pudo leer los resultados de Samba AD.');
            }

            $cuentas = [];

            for ($i = 0; $i < (int) ($entries['count'] ?? 0); $i++) {
                $entry = $entries[$i];

                $sam = $entry['samaccountname'][0] ?? null;
                $upn = $entry['userprincipalname'][0] ?? null;

                $uac = (int) ($entry['useraccountcontrol'][0] ?? 512);
                $deshabilitada = ($uac & self::UAC_ACCOUNTDISABLE) === self::UAC_ACCOUNTDISABLE;

                $cuentas[] = new RemoteAccount(
                    subsystemSlug: $this->slug(),
                    externalAccountId: (string) $sam,
                    login: $sam,
                    email: $entry['mail'][0] ?? null,
                    cpf: blank($entry['employeenumber'][0] ?? null) ? null : (string) $entry['employeenumber'][0],
                    estado: $deshabilitada ? 'suspendido' : 'activo',
                    nombreCompleto: $entry['displayname'][0] ?? $entry['cn'][0] ?? null,
                    meta: [
                        'dn' => $entry['dn'] ?? null,
                        'sAMAccountName' => $sam,
                        'upn' => $upn,
                        'userAccountControl' => $uac,
                    ],
                );
            }

            return $cuentas;
        } finally {
            $this->unbind($ldap);
        }
    }

    private function ldapError($ldap): string
    {
        $errno = @ldap_errno($ldap);
        $error = @ldap_error($ldap);
        $diagnostic = '';
        @ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);

        $message = "[{$errno}] {$error}";
        if (! empty($diagnostic)) {
            $message .= " - {$diagnostic}";
        }

        return $message;
    }
}
