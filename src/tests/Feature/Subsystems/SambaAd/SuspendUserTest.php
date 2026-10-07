<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Services\Subsystems\FakeLdapDirectory;

/**
 * Sprint 3.3 — suspendUser()/reactivateUser() against a simulated directory.
 *
 * Both are the same operation in AD: a flag on userAccountControl, OR'ed on or
 * masked off. What matters is that the *other* bits survive — AD uses that field
 * for profile bits too, and writing a constant 514 over it would silently strip
 * whatever the account needed.
 */
class SuspendUserTest extends SambaAdTestCase
{
    public function test_suspender_añade_la_bandera_de_deshabilitado_conservando_el_resto(): void
    {
        // The account also carries AD's "normal account" bit; only ACCOUNTDISABLE
        // may be added.
        $account = $this->sambaAccount(userAccountControl: 512);

        $result = $this->samba()->suspendUser($account, ['motivo_suspension' => 'Licencia']);

        $this->assertTrue($result->success, $result->mensaje ?? '');
        $this->assertSame('suspendido', $result->estado);

        $llamadas = FakeLdapDirectory::callsTo('ldap_mod_replace');

        $this->assertCount(1, $llamadas);
        // 512 | 2 = 514, so the profile bits are still there.
        $this->assertSame('514', (string) $llamadas[0][1][1]['userAccountControl']);
    }

    public function test_reactivar_quita_la_bandera_conservando_el_resto(): void
    {
        $account = $this->sambaAccount(userAccountControl: 514);

        $result = $this->samba()->reactivateUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        $this->assertSame('512', (string) FakeLdapDirectory::callsTo('ldap_mod_replace')[0][1][1]['userAccountControl']);
    }

    public function test_ya_suspendida_no_rompe_nada_al_suspenderla_de_nuevo(): void
    {
        $account = $this->sambaAccount(userAccountControl: 514);

        $result = $this->samba()->suspendUser($account, []);

        $this->assertTrue($result->success);
        // 514 | 2 is still 514: idempotent, and the directory is written once.
        $this->assertSame('514', (string) FakeLdapDirectory::callsTo('ldap_mod_replace')[0][1][1]['userAccountControl']);
    }

    public function test_un_dominio_que_rechaza_la_escritura_se_reporta_como_fallo(): void
    {
        $account = $this->sambaAccount();
        FakeLdapDirectory::$succeeds['ldap_mod_replace'] = false;

        $result = $this->samba()->suspendUser($account, []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('userAccountControl', $result->mensaje);
    }

    public function test_una_cuenta_inexistente_no_se_suspende(): void
    {
        $account = $this->sambaAccount();
        FakeLdapDirectory::$users = [];

        $result = $this->samba()->suspendUser($account, []);

        $this->assertFalse($result->success);
        // The guard is that nothing was written: a failure to find the account
        // must never turn into a write to some other DN.
        $this->assertSame([], FakeLdapDirectory::callsTo('ldap_mod_replace'));
    }
}
