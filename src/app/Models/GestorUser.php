<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class GestorUser extends Model
{
    use HasFactory;

    protected $table = 'gestor_users';

    protected $fillable = [
        'nombre_completo',
        'cpf',
        'password_general',
        'telefono_personal',
        'telefono_trabajo',
        'email_personal',
        'direccion_particular',
        'usuario',
        'empresa',
    ];

    protected $hidden = [
        'password_general',
    ];

    protected static function booted(): void
    {
        // Garantiza que la contraseña general siempre se guarde hasheada,
        // sin importar desde dónde se asigne el atributo.
        static::saving(function (GestorUser $user) {
            $yaEstaHasheada = str_starts_with((string) $user->password_general, '$2y$')
                || str_starts_with((string) $user->password_general, '$argon2');

            if ($user->isDirty('password_general') && ! $yaEstaHasheada) {
                $user->password_general = Hash::make($user->password_general);
            }
        });
    }

    public function subsystemAccounts(): HasMany
    {
        return $this->hasMany(UserSubsystemAccount::class, 'gestor_user_id');
    }
}
