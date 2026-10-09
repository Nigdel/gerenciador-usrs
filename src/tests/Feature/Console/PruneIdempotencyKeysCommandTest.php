<?php

namespace Tests\Feature\Console;

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 5.3 — Poda de claves de idempotencia.
 *
 * La poda no es solo limpieza: pasado el TTL una clave deja de poder repetir su
 * respuesta. Si la fila se quedara para siempre, una petición de hace un año
 * seguiría devolviendo el alta de entonces —con su uuid, que ya nadie puede
 * consultar— en vez de ejecutarse de verdad. El comando es lo que convierte el
 * TTL en una regla y no en un comentario.
 */
class PruneIdempotencyKeysCommandTest extends TestCase
{
    use RefreshDatabase;

    private function clave(string $clave, ?string $caduca): IdempotencyKey
    {
        return IdempotencyKey::create([
            'key' => $clave,
            'payload_hash' => sha1('x'),
            'response' => json_encode(['operacion_id' => 'x']),
            'respuesta_hash' => sha1('y'),
            'status' => 201,
            'expires_at' => $caduca,
        ]);
    }

    public function test_no_hace_nada_si_no_hay_claves_caducadas(): void
    {
        $this->clave('vigente', now()->addHour());

        $this->artisan('idempotency:prune')
            ->expectsOutputToContain('No hay claves caducadas')
            ->assertSuccessful();

        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_borra_solo_las_caducadas(): void
    {
        $this->clave('vieja', now()->subHour());
        $this->clave('muy-vieja', now()->subDays(3));
        $this->clave('vigente', now()->addHour());

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertSame(['vigente'], IdempotencyKey::pluck('key')->all());
    }

    public function test_el_dry_run_no_borra_nada(): void
    {
        $this->clave('vieja', now()->subHour());

        $this->artisan('idempotency:prune --dry-run')
            ->expectsOutputToContain('1 clave(s) se borrarían')
            ->assertSuccessful();

        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_el_limite_recorta_cuantas_se_borran_en_esta_pasada(): void
    {
        $this->clave('a', now()->subHour());
        $this->clave('b', now()->subHour());
        $this->clave('c', now()->subHour());

        $this->artisan('idempotency:prune --limit=2')->assertSuccessful();

        // La pasada siguiente se lleva el resto: por eso el límite es un
        // recorte, no un tope de lo que se puede borrar.
        $this->assertSame(1, IdempotencyKey::count());

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertSame(0, IdempotencyKey::count());
    }
}
