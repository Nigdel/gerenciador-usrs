<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Services\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Sprint 5.3 — Idempotencia.
 *
 * The property under test is that a repeated request replays the first answer
 * instead of doing the work twice. `check()` returns the stored response on
 * the second call and null on the first, which is what lets a caller answer
 * "already done" without repeating the side effect.
 */
class IdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyService $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(IdempotencyService::class);
    }

    private function peticion(string $clave, string $cuerpo = '{"nombre":"Ana"}'): Request
    {
        return Request::create('/api/v1/usuarios', 'POST', [], [], [], [
            'HTTP_IDEMPOTENCY_KEY' => $clave,
            'CONTENT_TYPE' => 'application/json',
        ], $cuerpo);
    }

    public function test_sin_cabecera_no_hace_nada(): void
    {
        $peticion = Request::create('/api/v1/usuarios', 'POST');

        $this->assertNull($this->servicio->check($peticion));
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_una_clave_nueva_se_registra_y_no_devuelve_respuesta_previa(): void
    {
        $this->assertNull($this->servicio->check($this->peticion('clave-1')));

        $this->assertSame(1, IdempotencyKey::count());
        $this->assertSame(sha1('{"nombre":"Ana"}'), IdempotencyKey::sole()->payload_hash);
    }

    public function test_la_segunda_peticion_con_la_misma_clave_devuelve_la_respuesta_guardada(): void
    {
        $this->servicio->check($this->peticion('clave-1'));
        $this->servicio->storeResponse('clave-1', ['id' => 7], 201);

        $respuesta = $this->servicio->check($this->peticion('clave-1'));

        $this->assertSame(['id' => 7], $respuesta['data']);
        $this->assertSame(201, $respuesta['status']);
    }

    public function test_guardar_una_clave_desconocida_no_falla(): void
    {
        // Sin `check()` previo no hay fila: guardar debe ser inocuo, porque el
        // middleware puede soltar la respuesta si la ruta no usa la clave.
        $this->servicio->storeResponse('no-existe', ['id' => 1], 200);

        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_cuerpos_distintos_con_la_misma_clave_conservan_su_huella(): void
    {
        $this->servicio->check($this->peticion('clave-1', '{"nombre":"Ana"}'));
        $this->servicio->check($this->peticion('clave-1', '{"nombre":"Otro"}'));

        // Una sola fila por clave: la clave es única y es la que decide. La
        // huella del primer cuerpo queda, que es lo que permite detectar que
        // el segundo intento no es el mismo.
        $registro = IdempotencyKey::sole();

        $this->assertSame(sha1('{"nombre":"Ana"}'), $registro->payload_hash);
    }
}
