<?php

namespace Tests\Feature\Subsystems\Glpi;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\GlpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DisableUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_glpi_disables_a_user_without_deleting_it(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
        ]);
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Acme',
        ]);
        $account = UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => 'glpi-42',
            'estado' => 'activo',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://glpi.test/apirest.php/User/glpi-42' => Http::response(['id' => 42, 'is_active' => false]),
        ]);

        $result = app(GlpiService::class)->disableUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('deshabilitado', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/User/glpi-42')
            && ($request->data()['input']['is_active'] ?? null) === false);
    }
}
