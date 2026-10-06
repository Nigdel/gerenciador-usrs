<?php

namespace App\Models;

use App\Enums\SubsystemAccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubsystemAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'gestor_user_id',
        'subsystem_id',
        'credencial_usuario',
        'external_account_id',
        'fecha_creacion',
        'estado',
        'inicio_suspension',
        'fin_suspension',
        'motivo_suspension',
        'meta',
    ];

    protected $casts = [
        'fecha_creacion' => 'datetime',
        'inicio_suspension' => 'datetime',
        'fin_suspension' => 'datetime',
        'meta' => 'array',
        'estado' => SubsystemAccountStatus::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(GestorUser::class, 'gestor_user_id');
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    /**
     * Marcar la cuenta como activa y borrar el rastro de la suspensión
     * (Fase 2.5).
     *
     * Los tres campos se limpian juntos a propósito: dejarlos sueltos produce
     * estados imposibles — una cuenta activa con fecha de suspensión, o una
     * suspensión sin motivo — que luego ninguna vista sabe explicar.
     *
     * Se devuelve el array de atributos para poder aplicarlo en un update()
     * junto a lo que cada camino necesite (por ejemplo 'meta').
     *
     * @return array{estado: string, inicio_suspension: null, fin_suspension: null, motivo_suspension: null}
     */
    public function atributosAlReactivar(): array
    {
        return [
            'estado' => SubsystemAccountStatus::Activo->value,
            'inicio_suspension' => null,
            'fin_suspension' => null,
            'motivo_suspension' => null,
        ];
    }
}
