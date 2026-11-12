<?php

namespace App\Models;

use App\Enums\TermoEstado;
use App\Enums\TermoTipo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Termo de responsabilidad de un medio.
 *
 * El registro es inmutable en lo que documenta: el snapshot `datos` no se
 * vuelve a calcular. Lo que sí cambia es el estado y los PDF (firmado,
 * anulación), que es el ciclo de vida del documento.
 */
class Termo extends Model
{
    protected $table = 'termos';

    protected $fillable = [
        'gestor_user_id',
        'user_subsystem_account_id',
        'item_type',
        'item_id',
        'item_name',
        'item_serial',
        'tipo',
        'estado',
        'datos',
        'pdf_generado',
        'pdf_firmado',
        'generado_at',
        'enviado_at',
        'firmado_at',
        'anulado_at',
        'motivo_anulacion',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'datos' => 'array',
        'tipo' => TermoTipo::class,
        'estado' => TermoEstado::class,
        'generado_at' => 'datetime',
        'enviado_at' => 'datetime',
        'firmado_at' => 'datetime',
        'anulado_at' => 'datetime',
    ];

    public function gestorUser(): BelongsTo
    {
        return $this->belongsTo(GestorUser::class, 'gestor_user_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(UserSubsystemAccount::class, 'user_subsystem_account_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', '!=', TermoEstado::Anulado->value);
    }

    public function scopeDelMedio(Builder $query, string $itemType, int|string $itemId): Builder
    {
        return $query->where('item_type', $itemType)->where('item_id', $itemId);
    }

    public function esVigente(): bool
    {
        return $this->estado->esVigente();
    }

    public function permiteSubirFirmado(): bool
    {
        return $this->estado->permiteSubirFirmado();
    }

    public function permiteAnular(): bool
    {
        return $this->estado->permiteAnular();
    }

    /**
     * Los datos de una sección del snapshot, sin tener que repetir el null
     * coalescing en la plantilla del PDF.
     *
     * @return array<string, mixed>
     */
    public function seccion(string $nombre): array
    {
        $seccion = $this->datos[$nombre] ?? [];

        return is_array($seccion) ? $seccion : [];
    }

    public function valor(string $seccion, string $clave, ?string $defecto = null): ?string
    {
        $valor = $this->seccion($seccion)[$clave] ?? null;

        if ($valor === null || $valor === '') {
            return $defecto;
        }

        return is_scalar($valor) ? (string) $valor : $defecto;
    }
}
