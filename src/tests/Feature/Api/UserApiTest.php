<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_provision_user_with_api()
    {
        $this->tokenConAbility(ApiAbility::Provisionar);

        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test',
            'api_config' => ['email' => 'a@b.com', 'password' => 'secret'],
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);

        $payload = [
            'cpf' => '12345678901',
            'nombre_completo' => 'Api Test User',
            'empresa' => 'Test Company',
        ];

        Http::fake([
            'adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token'], 200),
            'adagio.test/*' => Http::response(null, 404), // CPF no encontrado en Adagio
        ]);

        $response = $this->postJson('/api/usuarios/provisionar', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'usuario' => [
                'id',
                'nombre_completo',
                'cpf',
                'usuario',
            ],
            'subsistemas',
        ]);

        $this->assertDatabaseHas('gestor_users', [
            'cpf' => '12345678901',
            'nombre_completo' => 'Api Test User',
        ]);
    }

    public function test_can_suspend_user_with_api()
    {
        $this->tokenConAbility(ApiAbility::Suspender);

        $user = GestorUser::create([
            'cpf' => '12345678901',
            'nombre_completo' => 'Suspend User',
            'empresa' => 'Company',
            'password_general' => 'password123',
            'usuario' => 'suspend.user',
        ]);

        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
            'api_config' => ['token' => '123'],
            'activo' => true,
            'es_proveedor_identidad' => false,
        ]);

        $user->subsystemAccounts()->create([
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'suspend.user@glpi',
            'external_account_id' => 'EXT-123',
            'estado' => 'activo',
        ]);

        Http::fake();

        $payload = [
            'cpf' => '12345678901',
            'motivo_suspension' => 'Suspension por Api',
            'subsistemas' => ['glpi'],
        ];

        $response = $this->postJson('/api/usuarios/suspender', $payload);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'subsistemas',
        ]);

        $this->assertDatabaseHas('user_subsystem_accounts', [
            'external_account_id' => 'EXT-123',
            'estado' => 'suspendido',
            'motivo_suspension' => 'Suspension por Api',
        ]);
    }

    /**
     * Emite un token real con una ability concreta y autentica la petición
     * con cabecera Authorization.
     *
     * No vale actingAs(): con sanctum.guard vacío la sesión web no autentica
     * la API. Ni Sanctum::actingAs(): su token transitorio responde true a
     * cualquier ability.
     */
    private function tokenConAbility(ApiAbility $ability): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test', [$ability->value])
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }
}
