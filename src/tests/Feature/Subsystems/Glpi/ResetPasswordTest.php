<?php

namespace Tests\Feature\Subsystems\Glpi;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\GlpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_glpi_updates_both_password_fields(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
        ]);
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
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
        Http::fake(['https://glpi.test/apirest.php/User/glpi-42' => Http::response([])]);

        $result = app(GlpiService::class)->resetPassword($account, 'NewPassword123!');

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/User/glpi-42')
            && ($request->data()['input']['password'] ?? null) === 'NewPassword123!'
            && ($request->data()['input']['password2'] ?? null) === 'NewPassword123!');
    }
}
