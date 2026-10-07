<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Models\Subsystem;
use App\Services\Subsystems\FakeLdapDirectory;
use App\Services\Subsystems\SambaAdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The validation guards, which never reach the directory.
 *
 * The rest of createUser() —the DN, the three-step creation and both rollbacks—
 * is in DirectoryCreateUserTest, which needs the fake directory.
 */
class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    private function subsystem(): Subsystem
    {
        return Subsystem::create([
            'nombre' => 'Samba AD',
            'slug' => 'sambaad',
            'api_config' => ['host' => ''],
        ]);
    }

    public function test_samba_ad_rejects_creation_when_required_user_data_is_missing(): void
    {
        $result = app(SambaAdService::class)->createUser([], $this->subsystem());

        $this->assertFalse($result->success);
        $this->assertSame('Faltan datos obligatorios: usuario y nombre_completo', $result->mensaje);
    }

    public function test_samba_ad_rejects_creation_without_a_display_name(): void
    {
        $result = app(SambaAdService::class)->createUser(
            ['usuario' => 'jsilva'],
            $this->subsystem(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('Faltan datos obligatorios: usuario y nombre_completo', $result->mensaje);
    }

    public function test_samba_ad_rejects_a_login_longer_than_active_directory_allows(): void
    {
        // El límite es de sAMAccountName, no del login de la aplicación: es el
        // atributo de AD y no admite más de 20 caracteres.
        $result = app(SambaAdService::class)->createUser([
            'usuario' => 'jsilvacostadepaulasegur',
            'nombre_completo' => 'João Silva Costa',
        ], $this->subsystem());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('excede el máximo de 20 caracteres', $result->mensaje);
    }

    public function test_samba_ad_accepts_a_login_of_exactly_twenty_characters(): void
    {
        require_once __DIR__.'/fakes-ldap.php';
        FakeLdapDirectory::reset();

        $result = app(SambaAdService::class)->createUser([
            'usuario' => 'jsilvacostadepaulase',
            'nombre_completo' => 'João Silva Costa',
        ], $this->subsystem());

        // El de 20 pasa el corte del lado de AD y llega a la conexión, que aquí
        // falla porque el subsystem no tiene host. Lo que importa es que el
        // fallo ya no es el del límite: un > en vez de >= devolvería el mismo
        // mensaje y el test pasaría sin distinguir los dos casos.
        $this->assertStringNotContainsString('excede el máximo de 20 caracteres', $result->mensaje);
    }
}
