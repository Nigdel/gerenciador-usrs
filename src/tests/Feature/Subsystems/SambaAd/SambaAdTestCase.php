<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\FakeLdapDirectory;
use App\Services\Subsystems\SambaAdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base for the Samba AD tests that actually reach the directory.
 *
 * The other SambaAd tests extend SubsystemTestCase and only check the
 * configuration guards. These load the simulated directory instead, which is
 * what lets them exercise userAccountControl masking, DN resolution and
 * ldap_rename for real.
 */
abstract class SambaAdTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/fakes-ldap.php';

        FakeLdapDirectory::reset();

        $this->actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        FakeLdapDirectory::reset();

        parent::tearDown();
    }

    /**
     * A subsystem configured well enough to bind, and an account in it.
     *
     * Defaults are a reachable-but-not-connected directory: the fake accepts the
     * bind and the search, so a test that does not say otherwise is testing the
     * driver's logic rather than its connection failure handling.
     */
    protected function sambaAccount(int $userAccountControl = 512): UserSubsystemAccount
    {
        $subsystem = Subsystem::create([
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

        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        FakeLdapDirectory::addUser('sambaad-42', $userAccountControl);

        return UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'sambaad-42',
            'external_account_id' => 'sambaad-42',
            'estado' => 'activo',
        ]);
    }

    protected function samba(): SambaAdService
    {
        return app(SambaAdService::class);
    }
}
