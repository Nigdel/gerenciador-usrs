<?php

namespace App\Models;

use App\Enums\OperationAccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDiscrepancy extends Model
{
    use HasFactory;

    protected $table = 'account_discrepancies';

    protected $fillable = [
        'provisioning_operation_id',
        'user_subsystem_account_id',
        'subsystem',
        'local_estado',
        'remote_estado',
        'detectada_at',
        'resuelta_at',
        'resolucion',
        'actor_origen',
    ];

    protected $casts = [
        'detectada_at' => 'datetime',
        'resuelta_at' => 'datetime',
        'local_estado' => OperationAccountStatus::class,
        'resolucion' => 'string',
    ];

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(ProvisioningOperation::class, 'provisioning_operation_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(UserSubsystemAccount::class, 'user_subsystem_account_id');
    }
}