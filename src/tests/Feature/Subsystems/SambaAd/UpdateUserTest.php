<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Services\Subsystems\FakeLdapDirectory;

/**
 * Sprint 3.3 — updateUser() against a simulated directory.
 *
 * The interesting case is the one the plan calls out: cn is part of the DN's
 * RDN, so changing the name means renaming the object. A rename that fails must
 * NOT sink the whole operation — the contact attributes are already applied and
 * the account is still usable, so the driver reports success with a warning.
 * Getting this wrong in either direction is costly: reporting failure would
 * make the operator retry a half-applied change, and silently dropping the
 * warning would hide a directory whose CN no longer matches the user's name.
 */
class UpdateUserTest extends SambaAdTestCase
{
    private const NOMBRE = 'Ana Paula Silva';

    public function test_actualiza_los_atributos_de_contacto(): void
    {
        $account = $this->sambaAccount();

        $result = $this->samba()->updateUser($account, [
            'nombre_completo' => self::NOMBRE,
            'email_personal' => 'ana.silva@personal.test',
        ]);

        $this->assertTrue($result->success, $result->mensaje ?? '');

        $entry = FakeLdapDirectory::callsTo('ldap_mod_replace')[0][1][1];

        $this->assertSame(self::NOMBRE, $entry['displayName']);
        $this->assertSame('Ana', $entry['givenName']);
        $this->assertSame('Paula Silva', $entry['sn']);
        $this->assertSame('ana.silva@personal.test', $entry['mail']);
    }

    public function test_no_toca_la_identidad_de_la_cuenta(): void
    {
        $account = $this->sambaAccount();

        $this->samba()->updateUser($account, [
            'nombre_completo' => self::NOMBRE,
            'usuario' => 'otro.login',
        ]);

        $entry = FakeLdapDirectory::callsTo('ldap_mod_replace')[0][1][1];

        // sAMAccountName and userPrincipalName are the key the account was
        // created with; renaming them is a different, riskier operation.
        $this->assertArrayNotHasKey('sAMAccountName', $entry);
        $this->assertArrayNotHasKey('userPrincipalName', $entry);
        $this->assertArrayNotHasKey('otro.login', $entry);
    }

    public function test_el_nombre_igual_no_dispara_un_rename_innecesario(): void
    {
        $account = $this->sambaAccount();

        $result = $this->samba()->updateUser($account, ['nombre_completo' => 'sambaad-42']);

        $this->assertTrue($result->success);
        // The account's CN already is the login, so this is the no-op case.
        $this->assertSame([], FakeLdapDirectory::$renames);
    }

    public function test_un_cn_que_no_se_puede_renombrar_devuelve_exito_con_aviso(): void
    {
        $account = $this->sambaAccount();
        FakeLdapDirectory::$succeeds['ldap_rename'] = false;

        $result = $this->samba()->updateUser($account, ['nombre_completo' => self::NOMBRE]);

        // This is the rule from el plan: the attributes are applied and the
        // account is still recognizable, so this is not a failed operation.
        $this->assertTrue($result->success);
        $this->assertNotNull($result->mensaje);
        $this->assertStringContainsString('CN', $result->mensaje);

        // The DN it kept is the old one, so the caller knows where the user lives.
        $this->assertSame('CN=sambaad-42,OU=Usuarios,DC=klios,DC=br', $result->raw['dn']);
    }

    public function test_un_rename_correcto_deja_el_dn_nuevo_y_el_cn_actualizado(): void
    {
        $account = $this->sambaAccount();

        $result = $this->samba()->updateUser($account, ['nombre_completo' => self::NOMBRE]);

        $this->assertTrue($result->success);
        $this->assertSame('CN=Ana Paula Silva,OU=Usuarios,DC=klios,DC=br', $result->raw['dn']);

        // Two writes: the attributes, then the cn on the renamed DN.
        $this->assertCount(2, FakeLdapDirectory::callsTo('ldap_mod_replace'));
        $this->assertSame(
            ['cn' => self::NOMBRE],
            FakeLdapDirectory::callsTo('ldap_mod_replace')[1][1][1],
        );
    }

    public function test_falta_el_nombre_no_toca_el_directorio(): void
    {
        $account = $this->sambaAccount();

        $result = $this->samba()->updateUser($account, ['email_personal' => 'ana@personal.test']);

        $this->assertFalse($result->success);
        $this->assertSame([], FakeLdapDirectory::callsTo('ldap_mod_replace'));
    }

    public function test_un_dominio_que_rechaza_los_atributos_es_un_fallo(): void
    {
        $account = $this->sambaAccount();
        FakeLdapDirectory::$succeeds['ldap_mod_replace'] = false;

        $result = $this->samba()->updateUser($account, ['nombre_completo' => self::NOMBRE]);

        // Distinct from the rename case: here nothing was applied, so there is
        // nothing to call it a partial success.
        $this->assertFalse($result->success);
        $this->assertSame([], FakeLdapDirectory::$renames);
    }
}
