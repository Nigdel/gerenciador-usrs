<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'email',
    'password',
    'cpf',
    'telefone_pessoal',
    'telefone_servico',
    'empresa',
    'cargo',
    'externo',
    'encarregado_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'externo' => 'boolean',
    ];

    public function encarregado()
    {
        return $this->belongsTo(User::class, 'encarregado_id');
    }

    public function subordinados()
    {
        return $this->hasMany(User::class, 'encarregado_id');
    }

    public function subsystemAccounts(): HasMany
    {
        return $this->hasMany(UserSubsystemAccount::class, 'gestor_user_id');
    }
}
