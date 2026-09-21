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
}
