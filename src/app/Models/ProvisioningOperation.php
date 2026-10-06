<?php

namespace App\Models;

use App\Enums\OperationStatus;
use App\Enums\OperationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Una acción que salió a los subsistemas y su resultado (Fase 3.2).
 *
 * Nace cuando el operador pulsa un botón y se actualiza mientras los jobs van
 * tocando cada cuenta. Es lo que sustituye al flash de sesión: el resultado
 * sobrevive a que se recargue la página.
 */
class ProvisioningOperation extends Model
{
    use HasFactory;

    protected $table = 'provisioning_operations';

    protected $fillable = [
        'uuid',
        'gestor_user_id',
        'tipo',
        'estado',
        'payload',
        'actor_id',
        'actor_nombre',
        'origen',
        'iniciada_at',
        'terminada_at',
    ];

    protected $casts = [
        'tipo' => OperationType::class,
        'estado' => OperationStatus::class,
        'payload' => 'encrypted:array',
        'iniciada_at' => 'datetime',
        'terminada_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $operacion) {
            $operacion->uuid ??= (string) Str::uuid();
        });
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(GestorUser::class, 'gestor_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function cuentas(): HasMany
    {
        return $this->hasMany(ProvisioningOperationAccount::class, 'provisioning_operation_id');
    }

    /**
     * Sin pendientes no queda nada que pueda cambiar la operación: el polling
     * del panel para aquí.
     */
    public function estaTerminada(): bool
    {
        return $this->estado->terminada();
    }

    /**
     * Autor legible. Se congela al escribir para que borrar al operador no
     * deje la entrada sin nombre.
     */
    public function autor(): string
    {
        return $this->actor_nombre ?: 'Sistema';
    }

    /**
     * Resumen de una línea para el panel: «2 correctas, 1 con error».
     */
    public function resumen(): string
    {
        if ($this->estado === OperationStatus::Pendiente) {
            return 'Sin procesar';
        }

        $partes = [];

        if ($this->exitos > 0) {
            $partes[] = $this->exitos.' correctas';
        }

        if ($this->errores > 0) {
            $partes[] = $this->errores.' con error';
        }

        if ($this->pendientes > 0) {
            $partes[] = $this->pendientes.' pendientes';
        }

        return $partes === [] ? '—' : implode(', ', $partes);
    }
}