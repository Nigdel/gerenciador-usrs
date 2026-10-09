<?php

namespace Tests\Feature;

use App\Services\SecretService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 5.2 — Secrets.
 *
 * The contract is deliberately narrow: the service never invents a value. If a
 * secret is missing from the resolved configuration it throws instead of
 * returning an empty string, because an empty APP_KEY does not fail loudly —
 * it fails later, during decryption, in a place nobody is looking.
 */
class SecretServiceTest extends TestCase
{
    use RefreshDatabase;

    private SecretService $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(SecretService::class);
    }

    public function test_devuelve_el_app_key_resuelto(): void
    {
        config(['app.key' => 'base64:clave-de-prueba']);

        $this->assertSame('base64:clave-de-prueba', $this->servicio->getAppKey());
    }

    public function test_falla_si_el_app_key_no_esta_definido(): void
    {
        config(['app.key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY is not set.');

        $this->servicio->getAppKey();
    }

    public function test_devuelve_la_contrasena_de_mysql_desde_la_configuracion(): void
    {
        config(['database.connections.mysql.password' => 'secreto']);

        $this->assertSame('secreto', $this->servicio->getMysqlRootPassword());
        $this->assertSame('secreto', $this->servicio->getMysqlUserPassword());
    }

    public function test_falla_si_la_contrasena_de_mysql_no_esta_definida(): void
    {
        config(['database.connections.mysql.password' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_PASSWORD is not set.');

        $this->servicio->getMysqlRootPassword();
    }

    public function test_falla_si_la_contrasena_de_mysql_no_esta_definida_al_pedir_la_de_usuario(): void
    {
        config(['database.connections.mysql.password' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_PASSWORD is not set.');

        $this->servicio->getMysqlUserPassword();
    }
}
