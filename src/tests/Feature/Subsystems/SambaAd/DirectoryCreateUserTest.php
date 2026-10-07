<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Models\Subsystem;
use App\Services\Subsystems\FakeLdapDirectory;

/**
 * createUser() against the simulated directory.
 *
 * The method creates the account, then sets the password, then enables it, and
 * rolls back the creation if either of the last two fails. That ordering is the
 * whole point of the method — a half-created Samba AD account is an account
 * nobody can see or fix from here — so these tests are as much about what gets
 * rolled back as about what gets returned.
 *
 * The guards that never reach the directory (missing data, login too long) are
 * in CreateUserTest.
 */
class DirectoryCreateUserTest extends SambaAdTestCase
{
    private function subsystem(): Subsystem
    {
        // El subsystem lo crea sambaAccount(); aquí no hay cuenta que crear,
        // pero sí hace falta uno contra el que hablar.
        return Subsystem::create([
            'nombre' => 'Samba AD',
            'slug' => 'sambaad',
            'api_url' => 'ldap://dc1.test',
            'api_config' => [
                'host' => 'dc1.test',
                'port' => 389,
                'use_ldaps' => false,
                'bind_dn' => 'cn=admin,dc=klios,dc=br',
                'bind_password' => 'secret',
                'users_ou' => 'OU=Usuarios,DC=klios,DC=br',
                'dominio' => 'klios.br',
            ],
            'activo' => true,
        ]);
    }

    private function userData(array $extra = []): array
    {
        return array_merge([
            'usuario' => 'jsilva',
            'nombre_completo' => 'João Silva',
            'password_general' => 'SenhaForte123!',
        ], $extra);
    }

    public function test_creates_the_account_with_the_password_and_enables_it(): void
    {
        $result = $this->samba()->createUser($this->userData(), $this->subsystem());

        $this->assertTrue($result->success);
        $this->assertSame('jsilva', $result->credencialUsuario);
        $this->assertSame('jsilva', $result->externalAccountId);
        $this->assertSame('activo', $result->estado);
    }

    public function test_creates_the_entry_with_the_expected_attributes(): void
    {
        $this->samba()->createUser($this->userData(), $this->subsystem());

        $adds = FakeLdapDirectory::callsTo('ldap_add');
        $this->assertCount(1, $adds);

        $entry = $adds[0][1][1];

        $this->assertSame('jsilva', $entry['sAMAccountName']);
        $this->assertSame('jsilva@klios.br', $entry['userPrincipalName']);
        $this->assertSame('João Silva', $entry['cn']);
        $this->assertSame('João Silva', $entry['displayName']);
        $this->assertSame(['top', 'person', 'organizationalPerson', 'user'], $entry['objectClass']);
    }

    public function test_creates_the_entry_under_a_dn_built_from_the_display_name(): void
    {
        $this->samba()->createUser($this->userData(), $this->subsystem());

        // El RDN es el nombre completo, no el login: es lo que muestra el
        // el gestor de usuarios de AD y lo que el usuario reconoce como suyo.
        $this->assertSame(
            'CN=João Silva,OU=Usuarios,DC=klios,DC=br',
            FakeLdapDirectory::callsTo('ldap_add')[0][1][0],
        );
    }

    public function test_creates_the_account_disabled_so_the_password_can_be_set_after(): void
    {
        $this->samba()->createUser($this->userData(), $this->subsystem());

        // 514 = 512 (NORMAL_ACCOUNT) + 2 (ACCOUNTDISABLE). Crear la cuenta
        // deshabilitada y habilitarla al final es lo que evita dejar un
        // usuario capaz de autenticarse con una contraseña sin definir.
        $entry = FakeLdapDirectory::callsTo('ldap_add')[0][1][1];
        $this->assertSame('514', $entry['userAccountControl']);
    }

    public function test_sets_the_password_encoded_as_utf16_with_the_quoting_ad_expects(): void
    {
        $this->samba()->createUser($this->userData(), $this->subsystem());

        $mods = FakeLdapDirectory::callsTo('ldap_mod_replace');

        $this->assertCount(2, $mods);
        $this->assertSame(
            iconv('UTF-8', 'UTF-16LE', '"SenhaForte123!"'),
            $mods[0][1][1]['unicodePwd'],
        );
    }

    public function test_enables_the_account_after_setting_the_password(): void
    {
        $this->samba()->createUser($this->userData(), $this->subsystem());

        // El orden importa: password antes que habilitación.
        $mods = FakeLdapDirectory::callsTo('ldap_mod_replace');
        $this->assertArrayHasKey('unicodePwd', $mods[0][1][1]);
        $this->assertSame(['userAccountControl' => '512'], $mods[1][1][1]);
    }

    public function test_adds_the_personal_email_and_the_document_when_given(): void
    {
        $this->samba()->createUser($this->userData([
            'email_personal' => 'joao.silva@klios.br',
            'cpf' => '123.456.789-09',
        ]), $this->subsystem());

        $entry = FakeLdapDirectory::callsTo('ldap_add')[0][1][1];

        $this->assertSame('joao.silva@klios.br', $entry['mail']);
        // Sin puntos ni guiones: se guarda en employeeNumber, que es un
        // atributo numérico y AD no los admite.
        $this->assertSame('12345678909', $entry['employeeNumber']);
    }

    public function test_omits_the_optional_attributes_when_they_are_not_given(): void
    {
        $this->samba()->createUser($this->userData(), $this->subsystem());

        $entry = FakeLdapDirectory::callsTo('ldap_add')[0][1][1];

        $this->assertArrayNotHasKey('mail', $entry);
        $this->assertArrayNotHasKey('employeeNumber', $entry);
    }

    public function test_returns_the_new_dn_and_principal_name_as_raw(): void
    {
        $result = $this->samba()->createUser($this->userData(), $this->subsystem());

        $this->assertSame('CN=João Silva,OU=Usuarios,DC=klios,DC=br', $result->raw['dn']);
        $this->assertSame('jsilva@klios.br', $result->raw['upn']);
        $this->assertSame('jsilva', $result->raw['sAMAccountName']);
    }

    public function test_reuses_an_existing_account_instead_of_creating_a_second_one(): void
    {
        FakeLdapDirectory::addUser('jsilva');

        $result = $this->samba()->createUser($this->userData(), $this->subsystem());

        $this->assertTrue($result->success);
        $this->assertSame('jsilva', $result->credencialUsuario);
        $this->assertStringContainsString('se reutilizó', $result->mensaje);

        // Idempotencia: no toca el directorio.
        $this->assertCount(0, FakeLdapDirectory::callsTo('ldap_add'));
        $this->assertCount(0, FakeLdapDirectory::callsTo('ldap_mod_replace'));
    }

    public function test_fails_when_the_directory_rejects_the_creation(): void
    {
        FakeLdapDirectory::$succeeds['ldap_add'] = false;

        $result = $this->samba()->createUser($this->userData(), $this->subsystem());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Error al crear usuario en Samba AD', $result->mensaje);
        $this->assertStringContainsString('CN=João Silva,OU=Usuarios', $result->mensaje);
    }

    public function test_rolls_back_the_creation_when_the_password_cannot_be_set(): void
    {
        FakeLdapDirectory::$succeeds['ldap_mod_replace'] = false;

        $result = $this->samba()->createUser($this->userData(), $this->subsystem());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('se revirtió la creación', $result->mensaje);

        // La cuenta se creó antes de fallar la contraseña: si no se borrara,
        // quedaría un usuario en AD que ni existe en la aplicación ni se puede
        // autenticar.
        $this->assertCount(1, FakeLdapDirectory::callsTo('ldap_delete'));
    }

    public function test_rolls_back_the_creation_when_the_account_cannot_be_enabled(): void
    {
        // Solo falla la segunda llamada: la contraseña se fija bien y falla la
        // habilitación, que es el orden real de los fallos.
        FakeLdapDirectory::$failsOnNth['ldap_mod_replace'] = 2;

        $result = $this->samba()->createUser($this->userData(), $this->subsystem());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No se pudo habilitar la cuenta', $result->mensaje);
        $this->assertStringContainsString('se revirtió la creación', $result->mensaje);

        $this->assertCount(1, FakeLdapDirectory::callsTo('ldap_delete'));
    }

    public function test_does_not_try_to_enable_an_account_it_could_not_create(): void
    {
        FakeLdapDirectory::$succeeds['ldap_add'] = false;

        $this->samba()->createUser($this->userData(), $this->subsystem());

        // Ni contraseña ni habilitación: sin cuenta no hay nada que activar.
        $this->assertCount(0, FakeLdapDirectory::callsTo('ldap_mod_replace'));
    }

    public function test_closes_the_connection_even_when_the_creation_fails(): void
    {
        FakeLdapDirectory::$succeeds['ldap_add'] = false;

        $this->samba()->createUser($this->userData(), $this->subsystem());

        $this->assertCount(1, FakeLdapDirectory::callsTo('ldap_unbind'));
    }
}
