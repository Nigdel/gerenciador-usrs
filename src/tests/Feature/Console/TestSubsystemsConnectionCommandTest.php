<?php

namespace Tests\Feature\Console;

use App\Models\Subsystem;
use App\Services\Subsystems\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sprint 6.6 — Prueba de conexión a todos los subsistemas activos.
 *
 * Lo que importa no es solo que el comando reporte bien, sino que un subsistema
 * roto no borre la información del resto: por eso el caso central es que un
 * fallo no detiene la pasada, y que lo que no se pudo probar no se contabilice
 * como una conexión fallida.
 *
 * Los drivers son el EmailService real bajo slugs distintos, con el HTTP falseado:
 * así se ejercita el registry y el driver de verdad, sin depender de la red.
 */
class TestSubsystemsConnectionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function subsistema(string $slug, bool $activo = true, string $apiUrl = ''): Subsystem
    {
        config(['subsystems.drivers.'.$slug => EmailService::class]);

        return Subsystem::create([
            'nombre' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'api_url' => $apiUrl !== '' ? $apiUrl : 'https://'.$slug.'.test',
            'api_config' => [],
            'activo' => $activo,
        ]);
    }

    public function test_prueba_cada_subsistema_activo_y_registra_el_resultado(): void
    {
        $sano = $this->subsistema('sano');
        $otro = $this->subsistema('otro-sano');
        $this->responden();

        $this->artisan('subsystems:test-conection')
            ->expectsOutputToContain('Conectaron 2 de 2 subsistemas probados.')
            ->assertSuccessful();

        foreach ([$sano, $otro] as $subsistema) {
            $subsistema->refresh();

            $this->assertTrue($subsistema->last_connection_test_success);
            $this->assertNotNull($subsistema->last_connection_test_at);
        }
    }

    public function test_no_hay_subsistemas_activos_que_probar(): void
    {
        $this->subsistema('apagado', activo: false);

        $this->artisan('subsystems:test-conection')
            ->expectsOutputToContain('No hay subsistemas activos que probar.')
            ->assertSuccessful();
    }

    public function test_no_prueba_los_subsistemas_inactivos(): void
    {
        Http::preventStrayRequests();
        $apagado = $this->subsistema('apagado', activo: false);

        $this->artisan('subsystems:test-conection')->assertSuccessful();

        // Si se hubiera preguntado por el inactivo, la respuesta falseada lo
        // habría dejado registrado.
        $this->assertNull($apagado->fresh()->last_connection_test_at);
        Http::assertNothingSent();
    }

    public function test_un_fallo_no_impide_probar_el_resto(): void
    {
        $roto = $this->subsistema('roto');
        $sano = $this->subsistema('sano');
        Http::preventStrayRequests();
        Http::fake([
            'https://sano.test/*' => Http::response([], 200),
            'https://roto.test/*' => Http::response([], 500),
        ]);

        $this->artisan('subsystems:test-conection')
            ->expectsOutputToContain('Sin conexión: roto.')
            ->expectsOutputToContain('Conectaron 1 de 2 subsistemas probados.')
            ->assertFailed();

        // El que respondió sí quedó registrado: un corte en un subsistema no
        // puede dejar a ciegas a los demás.
        $this->assertTrue($sano->fresh()->last_connection_test_success);
        $this->assertFalse($roto->fresh()->last_connection_test_success);
    }

    public function test_un_error_de_comunicacion_cuenta_como_prueba_fallida(): void
    {
        $roto = $this->subsistema('roto');
        $this->responden([
            'https://roto.test/*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->artisan('subsystems:test-conection')
            ->expectsOutputToContain('Sin conexión: roto.')
            // El motivo sale del error, no del cuerpo de la respuesta: prueba
            // que la excepción se capturó y no que hubo un 5xx.
            ->expectsOutputToContain('Connection timed out')
            ->assertFailed();

        // Se intentó de verdad: hay prueba, y su registro es el fallo.
        $this->assertFalse($roto->fresh()->last_connection_test_success);
        $this->assertNotNull($roto->fresh()->last_connection_test_at);
    }

    public function test_un_subsistema_sin_driver_se_reporta_sin_tocar_la_ultima_prueba(): void
    {
        $huerfano = Subsystem::create([
            'nombre' => 'Desconocido',
            'slug' => 'desconocido',
            'api_url' => 'https://desconocido.test',
            'api_config' => [],
            'activo' => true,
        ]);
        $huerfano->update([
            'last_connection_test_at' => now()->subDay(),
            'last_connection_test_success' => true,
        ]);
        $ultimaPrueba = $huerfano->last_connection_test_at->copy();

        // Sin driver no hubo prueba: last_connection_test_* guarda lo que la
        // última prueba real dio, y un false aquí sería un dato que nadie midió.
        $this->artisan('subsystems:test-conection')
            ->expectsOutputToContain('No existe un driver registrado')
            ->assertSuccessful();

        $huerfano->refresh();

        $this->assertTrue($huerfano->last_connection_test_success);
        $this->assertTrue($ultimaPrueba->equalTo($huerfano->last_connection_test_at));
    }

    /**
     * @param  array<string, mixed>  $ademas
     */
    private function responden(array $ademas = []): void
    {
        Http::preventStrayRequests();
        Http::fake($ademas + ['*' => Http::response([], 200)]);
    }
}
