<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El registro público está cerrado, así que users:create-admin es la única vía
 * para dejar la aplicación con un administrador.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_un_administrador(): void
    {
        $this->artisan('users:create-admin', [
            '--name' => 'Administrador',
            '--email' => 'admin@klios.com.br',
            '--password' => 'Admin@2026',
        ])->assertSuccessful();

        $admin = User::where('email', 'admin@klios.com.br')->first();

        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertSame('Administrador', $admin->name);
        $this->assertTrue(Hash::check('Admin@2026', $admin->password));
    }

    public function test_la_contrasena_se_guarda_hasheada_y_no_en_claro(): void
    {
        $this->artisan('users:create-admin', [
            '--email' => 'admin@klios.com.br',
            '--password' => 'Admin@2026',
        ])->assertSuccessful();

        $admin = User::where('email', 'admin@klios.com.br')->firstOrFail();

        $this->assertNotSame('Admin@2026', $admin->password);
    }

    public function test_no_crea_un_segundo_administrador(): void
    {
        User::factory()->admin()->create(['email' => 'primero@klios.com.br']);

        $this->artisan('users:create-admin', [
            '--email' => 'segundo@klios.com.br',
            '--password' => 'Admin@2026',
        ])->assertFailed();

        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertNull(User::where('email', 'segundo@klios.com.br')->first());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function invalidos(): array
    {
        return [
            'correo repetido' => ['admin@klios.com.br', 'Admin@2026'],
            'contrasena corta' => ['nuevo@klios.com.br', 'Aa1@aaa'],
            'contrasena sin simbolo' => ['nuevo@klios.com.br', 'Admin2026x'],
            'contrasena sin mayuscula' => ['nuevo@klios.com.br', 'admin@2026'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_rechaza_datos_invalidos(string $email, string $password): void
    {
        User::factory()->admin()->create(['email' => 'admin@klios.com.br']);

        $this->artisan('users:create-admin', [
            '--email' => $email,
            '--password' => $password,
        ])->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_rechaza_un_correo_invalido(): void
    {
        $this->artisan('users:create-admin', [
            '--email' => 'no-es-un-correo',
            '--password' => 'Admin@2026',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
