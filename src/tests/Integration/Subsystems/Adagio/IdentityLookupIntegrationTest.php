<?php

namespace Tests\Integration\Subsystems\Adagio;

use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\AdagioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityLookupIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adagio_returns_empty_results_for_nonexistent_identity_data(): void
    {
        $apiUrl = env('ADAGIO_API_URL', env('ADAGIO_BASE_URL'));
        $this->assertNotEmpty($apiUrl, 'Configure ADAGIO_API_URL or ADAGIO_BASE_URL to run this live integration test.');

        $subsystem = Subsystem::create([
            'nombre' => 'Adagio Integration',
            'slug' => 'adagio',
            'api_url' => $apiUrl,
            'api_config' => [
                'email' => env('ADAGIO_EMAIL'),
                'password' => env('ADAGIO_PASSWORD'),
                'timeout' => 5,
            ],
            'es_proveedor_identidad' => true,
            'activo' => true,
        ]);
        $account = new UserSubsystemAccount([
            'external_account_id' => 'usuario-inexistente-'.uniqid(),
        ]);
        $account->setRelation('subsystem', $subsystem);
        $service = app(AdagioService::class);

        $this->assertNull($service->findByCpf('cpf-inexistente-'.uniqid()));
        $this->assertFalse($service->existsByEmail('email-inexistente-'.uniqid().'@example.invalid'));
        $this->assertFalse($service->getUserStatus($account)->success);
    }
}
