<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada del histórico de una cuenta en un subsistema.
 *
 * Solo se escribe: no se edita ni se borra nunca. Es un registro de auditoría,
 * no un estado editable.
 */
class AccountStateLog extends Model
{
    use HasFactory;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    protected $fillable = [
        'user_subsystem_account_id',
        'gestor_user_id',
        'subsystem_id',
        'evento',
        'cambios',
        'actor_id',
        'actor_nombre',
        'origen',
        'descripcion',
    ];

    protected $casts = [
        'cambios' => 'array',
        'created_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(UserSubsystemAccount::class, 'user_subsystem_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(GestorUser::class, 'gestor_user_id');
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Nombre legible del autor: el congelado en el momento, si el usuario ya no está. */
    public function autor(): string
    {
        return $this->actor_nombre ?: 'Sistema';
    }
}
