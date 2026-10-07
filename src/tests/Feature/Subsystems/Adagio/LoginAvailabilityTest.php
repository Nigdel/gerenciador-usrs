<?php

namespace Tests\Feature\Subsystems\Adagio;

use App\Services\Subsystems\AdagioService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

/**
 * Sprint 3.3 — loginEnUso() en Adagio.
 *
 * La regla que el plan marca para este driver: un 404 es «no hay propietario
 * con ese email», no una avería. Tratarlo como no comprobable haría que NINGÚN
 * login llegara a proponerse nunca y el generador agotaría sus 100 intentos
 * — es un fallo silencioso que se manifiesta como un 422 «no se encontró login
 * libre», muy lejos de su causa.
 */
class LoginAvailabilityTest extends SubsystemTestCase
{
    private function fakes(callable $propietarios): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $peticion) use ($propietarios) {
            if (str_contains($peticion->url(), 'kliosAnalise/login')) {
                return Http::response(['token' => 'fake-token']);
            }

            if (str_contains($peticion->url(), 'proprietarios/internos')) {
                return $propietarios($peticion);
            }

            return Http::response(null, 404);
        });
    }

    private function cuenta(array $extra = [])
    {
        return $this->makeAccount('adagio', array_merge([
            'email' => 'a@b.com',
            'password' => 'secret',
            'entidad_default' => 'klios',
        ], $extra), apiUrl: 'https://adagio.test/api');
    }

    private function adagio(): AdagioService
    {
        return app(AdagioService::class);
    }

    public function test_un_404_significa_login_libre(): void
    {
        $subsistema = $this->cuenta(['dominio' => 'empresa.com.br'])->subsystem;
        $this->fakes(fn () => Http::response(null, 404));

        $this->assertFalse(
            $this->adagio()->loginEnUso('ana.silva', 'klios', $subsistema),
            'Un 404 de Adagio es un «no», no «no se pudo comprobar».',
        );
    }

    public function test_un_propietario_existente_devuelve_el_login_ocupado(): void
    {
        $subsistema = $this->cuenta(['dominio' => 'empresa.com.br'])->subsystem;
        $this->fakes(fn () => Http::response(['usuario' => ['id' => 77, 'email' => 'ana.silva@empresa.com.br']]));

        $this->assertTrue($this->adagio()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_un_email_consultado_en_minusculas(): void
    {
        $subsistema = $this->cuenta(['dominio' => 'empresa.com.br'])->subsystem;
        $this->fakes(fn () => Http::response(null, 404));

        $this->adagio()->loginEnUso('Ana Silva', 'klios', $subsistema);

        // Adagio usa el email como credencial, así que el login se normaliza:
        // espacios fuera y minúsculas, o el 404 no significaría nada.
        Http::assertSent(fn ($request) => str_contains(
            rawurldecode($request->url()),
            'anasilva@empresa.com.br',
        ));
    }

    public function test_sin_dominio_no_se_inventa_una_comprobacion(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        Http::preventStrayRequests();

        $this->assertNull(
            $this->adagio()->loginEnUso('ana.silva', 'klios', $subsistema),
            'Sin dominio no hay forma de armar el email, así que no se comprueba.',
        );

        Http::assertNothingSent();
    }

    public function test_una_averia_de_adagio_no_declara_el_login_libre(): void
    {
        $subsistema = $this->cuenta(['dominio' => 'empresa.com.br'])->subsystem;
        $this->fakes(fn () => Http::response(['error' => 'boom'], 500));

        $this->assertNull($this->adagio()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_adagio_caido_no_declara_el_login_libre(): void
    {
        $subsistema = $this->cuenta(['dominio' => 'empresa.com.br'])->subsystem;
        Http::preventStrayRequests();
        Http::fake(function (Request $peticion) {
            if (str_contains($peticion->url(), 'kliosAnalise/login')) {
                return Http::response(['token' => 'fake-token']);
            }

            throw new ConnectionException('Connection timed out');
        });

        // loginEnUso() sí captura la excepción de red y devuelve null, a
        // diferencia de updateUser(), que la deja subir al job.
        $this->assertNull($this->adagio()->loginEnUso('ana.silva', 'klios', $subsistema));
    }
}
