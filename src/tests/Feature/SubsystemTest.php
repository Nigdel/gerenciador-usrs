<?php

namespace Tests\Feature;

use App\Models\Subsystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_subsystem_crud_pages_are_available(): void
    {
        $this->get('/subsystems')->assertOk();
        $this->get('/subsystems/create')->assertOk();

        $subsystem = Subsystem::create([
            'nombre' => 'Test subsystem',
            'slug' => 'test-subsystem',
        ]);

        $this->get(route('subsystems.show', $subsystem))->assertOk();
        $this->get(route('subsystems.edit', $subsystem))->assertOk();
    }

    public function test_subsystem_can_be_created_with_json_configuration(): void
    {
        $response = $this->post('/subsystems', [
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'descripcion' => 'Proveedor principal',
            'api_url' => 'https://adagio.example.com/api',
            'api_config' => '{"timeout":30}',
            'activo' => '1',
            'es_proveedor_identidad' => '1',
        ]);

        $response
            ->assertRedirect('/subsystems')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subsystems', [
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);

        $this->assertSame(['timeout' => 30], Subsystem::firstOrFail()->api_config);
    }

    public function test_subsystem_can_be_updated(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'activo' => true,
        ]);

        $this->put(route('subsystems.update', $subsystem), [
            'nombre' => 'GLPI Helpdesk',
            'slug' => 'glpi-helpdesk',
            'activo' => '0',
        ])->assertRedirect('/subsystems');

        $this->assertDatabaseHas('subsystems', [
            'id' => $subsystem->id,
            'nombre' => 'GLPI Helpdesk',
            'activo' => false,
        ]);
    }

    public function test_subsystem_can_be_deleted_without_accounts(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Slack',
            'slug' => 'slack',
        ]);

        $this->delete(route('subsystems.destroy', $subsystem))
            ->assertRedirect('/subsystems');

        $this->assertDatabaseMissing('subsystems', ['id' => $subsystem->id]);
    }
}
