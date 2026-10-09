<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasFactory;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key',
        'payload_hash',
        'provisioning_operation_id',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
    ];
}
