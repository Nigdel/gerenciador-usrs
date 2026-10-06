<?php

namespace App\Models;

use App\Enums\OperationAccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una cuenta dentro de una operación (Fase 3.2): el trabajo a hacer y cómo
 * acabó.
 *
 * En el alta la cuenta todavía no existe cuando se crea la fila (es el job el
 * que hace el updateOrCreate), por eso user_subsystem_account_id es nullable y
 * se rellena al terminar.
 */
class ProvisioningOperationAccount extends Model
{
    use HasFactory;

    protected $table = 'provisioning_operation_accounts';

    protected $fillable = [
        'provisioning_operation_id',
        'user_subsystem_account_id',
        'subsystem_id',
        'subsistema',
        'estado',
        'mensaje',
        'intentos',
        'payload',
    ];

    protected $casts = [
        'estado' => OperationAccountStatus::class,
        'payload' => 'array',
    ];

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(ProvisioningOperation::class, 'provisioning_operation_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(UserSubsystemAccount::class, 'user_subsystem_account_id');
    }

    public function subsistema(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }
}