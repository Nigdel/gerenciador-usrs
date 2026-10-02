<?php

namespace Tests\Feature\Subsystems\SambaAd;

use App\Models\Subsystem;
use App\Services\Subsystems\SambaAdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_samba_ad_rejects_creation_when_required_user_data_is_missing(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Samba AD',
            'slug' => 'sambaad',
            'api_config' => ['host' => ''],
        ]);

        $result = app(SambaAdService::class)->createUser([], $subsystem);

        $this->assertFalse($result->success);
        $this->assertSame('Faltan datos obligatorios: usuario y nombre_completo', $result->mensaje);
    }
}
