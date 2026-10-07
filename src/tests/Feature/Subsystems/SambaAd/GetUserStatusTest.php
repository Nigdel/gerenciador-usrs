<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Services\Subsystems\FakeLdapDirectory;

/**
 * Sprint 3.3 — getUserStatus() against a simulated directory.
 *
 * The existing SambaAd tests stop at the configuration guard, so userAccountControl
 * had never been read under test. That bit mask is the whole point of the method:
 * AD encodes "suspended" as a flag OR'ed onto whatever else the account needs,
 * so reading the raw value as a state would be wrong.
 */
class GetUserStatusTest extends SambaAdTestCase
{
    public function test_una_cuenta_sin_el_flag_de_deshabilitado_esta_activa(): void
    {
        $account = $this->sambaAccount(userAccountControl: 512);

        $result = $this->samba()->getUserStatus($account);

        $this->assertTrue($result->success, $result->mensaje ?? '');
        $this->assertSame('activo', $result->estado);
    }

    public function test_la_bandera_de_deshabilitado_convierte_la_cuenta_en_suspendida(): void
    {
        // 512 is NORMAL_ACCOUNT; 2 is ACCOUNTDISABLE. AD's own value for a
        // disabled normal account is 514.
        $account = $this->sambaAccount(userAccountControl: 514);

        $result = $this->samba()->getUserStatus($account);

        $this->assertTrue($result->success);
        $this->assertSame('suspendido', $result->estado);
        $this->assertSame(514, $result->raw['userAccountControl']);
    }

    public function test_otros_flags_del_perfil_no_confunden_la_lectura(): void
    {
        // A normal user that is also a member of a domain: bit 8 (NORMAL_ACCOUNT)
        // plus the group-membership flag AD sets routinely. Only ACCOUNTDISABLE
        // counts, so this must not be reported as suspended.
        $account = $this->sambaAccount(userAccountControl: 0x0200 | 512);

        $result = $this->samba()->getUserStatus($account);

        $this->assertSame('activo', $result->estado);
    }

    public function test_una_cuenta_inexistente_en_el_directorio_falla(): void
    {
        $account = $this->sambaAccount();
        FakeLdapDirectory::$users = [];

        $result = $this->samba()->getUserStatus($account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no encontrado', $result->mensaje);
    }

    public function test_un_bind_que_falla_no_se_confunde_con_un_usuario_inexistente(): void
    {
        $account = $this->sambaAccount();
        FakeLdapDirectory::$succeeds['ldap_bind'] = false;

        $result = $this->samba()->getUserStatus($account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Bind', $result->mensaje);
        // A directory we could not read is not evidence about the user.
        $this->assertSame([], FakeLdapDirectory::callsTo('ldap_mod_replace'));
    }
}
