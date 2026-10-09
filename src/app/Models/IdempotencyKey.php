<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una clave de idempotencia y lo que respondió su petición.
 *
 * `response` es el cuerpo TAL COMO se envió la primera vez —una cadena, no un
 * array—, y `status` su código. Guardarlos ya resueltos es lo que hace que la
 * repetición sea idéntica en bytes y no una reconstrucción que podría salir
 * distinta; `cast` a array convertiría las claves y el orden.
 */
class IdempotencyKey extends Model
{
    use HasFactory;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key',
        'payload_hash',
        'provisioning_operation_id',
        'response',
        'respuesta_hash',
        'status',
        'error',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * La operación que acabó produciendo esta petición, cuando se llegó a
     * crearla. Es lo que permite que el endpoint de consulta se resuelva sin
     * que la integración tenga que guardar el uuid aparte.
     */
    public function operacion(): BelongsTo
    {
        return $this->belongsTo(ProvisioningOperation::class, 'provisioning_operation_id');
    }

    /**
     * Enlaza la clave con la operación que acabó produciendo la petición.
     *
     * No es imprescindible para repetir —la respuesta guardada ya lleva su
     * uuid— pero permite saber qué filas pertenecen a una operación concreta y
     * purgar juntas las que se caducaron con ella.
     */
    public function enlazarOperacion(?int $operacionId): void
    {
        $this->forceFill(['provisioning_operation_id' => $operacionId])->save();
    }

    /**
     * Caducó: pasado el TTL la misma clave vuelve a ejecutarse de verdad.
     */
    public function caducada(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Ya terminó —correcta o con error— y su respuesta se puede repetir.
     */
    public function terminada(): bool
    {
        return $this->response !== null;
    }
}
