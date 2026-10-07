<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Models\Subsystem;
use App\Services\Subsystems\FakeLdapDirectory;

/**
 * Sprint 3.3 — loginEnUso() against a simulated directory.
 *
 * Three outcomes, and the middle one is the one that matters: true and false are
 * both answers, null means "could not tell". Returning false for a directory we
 * failed to read would propose a login that Samba itself would then reject, so
 * the tri-state is the contract worth pinning.
 */
class LoginAvailabilityTest extends SambaAdTestCase
{
    public function test_un_login_que_no_existe_esta_libre(): void
    {
        $this->sambaAccount();

        $this->assertFalse($this->samba()->loginEnUso('nuevo.login', 'klios', $this->subsistema()));
    }

    public function test_un_login_que_ya_existe_esta_ocupado(): void
    {
        $this->sambaAccount();

        $this->assertTrue($this->samba()->loginEnUso('sambaad-42', 'klios', $this->subsistema()));
    }

    public function test_una_busqueda_que_falla_no_declara_el_login_libre(): void
    {
        $this->sambaAccount();
        FakeLdapDirectory::$succeeds['ldap_search'] = false;

        $this->assertNull(
            $this->samba()->loginEnUso('nuevo.login', 'klios', $this->subsistema()),
            'Un directorio que no respondió no puede decir que el login está libre.',
        );
    }

    public function test_una_busqueda_que_devuelve_cero_resultados_no_es_un_error_de_directorio(): void
    {
        $this->sambaAccount();

        // The driver separates "found nobody" from "the directory errored": an
        // empty result is a real "no", and must not come back as null or the
        // generator would never propose a login.
        $this->assertFalse($this->samba()->loginEnUso('nuevo.login', 'klios', $this->subsistema()));
    }

    public function test_la_comprobacion_busca_tanto_por_samaccountname_como_por_upn(): void
    {
        $this->sambaAccount();

        $this->samba()->loginEnUso('nuevo.login', 'klios', $this->subsistema());

        $llamadas = FakeLdapDirectory::callsTo('ldap_search');
        // record() stores [function, arguments]; the filter is the second
        // argument the driver passed to ldap_search().
        $ultimo = $llamadas[count($llamadas) - 1][1][1];

        // A login already used as a UPN but not as a sAMAccountName still has
        // to be reported as taken.
        $this->assertStringContainsString('samaccountname', strtolower($ultimo));
        $this->assertStringContainsString('userprincipalname', strtolower($ultimo));
    }

    private function subsistema()
    {
        return Subsystem::where('slug', 'sambaad')->firstOrFail();
    }
}
