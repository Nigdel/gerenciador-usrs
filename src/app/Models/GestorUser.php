<?php

namespace App\Models;

use App\Enums\GestorUserStatus;
use Illuminate\Database\Eloquent\Builder;
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
        'estado',
        'baja_at',
        'motivo_baja',
    ];

    protected $casts = [
        'estado' => GestorUserStatus::class,
        'baja_at' => 'datetime',
    ];

    /**
     * Búsqueda por texto libre sobre los campos por los que se identifica a
     * alguien: nombre, usuario, empresa y CPF.
     *
     * Los OR van dentro de una closure porque el scope se encadena con otros
     * filtros: sin el grupo, un AND posterior solo se aplicaría al último
     * orWhere y el resto de las condiciones dejarían de filtrar en silencio.
     * El % se escapa para que un texto con % o _ busque literal.
     */
    public function scopeBuscar(Builder $query, ?string $q): Builder
    {
        $q = trim((string) $q);

        if ($q === '') {
            return $query;
        }

        $patron = '%'.addcslashes($q, '%_\\').'%';

        return $query->where(function (Builder $query) use ($patron) {
            $query->where('nombre_completo', 'like', $patron)
                ->orWhere('usuario', 'like', $patron)
                ->orWhere('empresa', 'like', $patron)
                ->orWhere('cpf', 'like', $patron);
        });
    }

    /**
     * Filtro por estado. El tipo es el enum, no un string: un valor que no sea
     * 'activo' ni 'baja' no llega a la consulta.
     */
    public function scopeDelEstado(Builder $query, ?GestorUserStatus $estado): Builder
    {
        if ($estado === null) {
            return $query;
        }

        return $query->where('estado', $estado->value);
    }

    /**
     * Filtro por subsistema: usuarios con al menos una cuenta en él. whereHas
     * evita duplicar al usuario cuando tiene varias cuentas en el mismo
     * subsistema, que es lo normal.
     */
    public function scopeEnSubsistema(Builder $query, ?string $slug): Builder
    {
        if ($slug === null || $slug === '') {
            return $query;
        }

        return $query->whereHas('subsystemAccounts.subsystem', function ($query) use ($slug) {
            $query->where('subsystems.slug', $slug);
        });
    }

    /**
     * Está dado de baja si su estado local es 'baja', con independencia de lo
     * que diga cada cuenta: un usuario puede estar de baja y tener todavía una
     * cuenta activa en un subsistema al que no llegó la baja.
     */
    public function estaDadoDeBaja(): bool
    {
        return $this->estado === GestorUserStatus::Baja;
    }

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

    /**
     * Operaciones que le han salido a los subsistemas (Fase 3.2), del más
     * reciente al más antiguo. Es lo que pinta el panel de la ficha.
     *
     * Va por gestor_user_id y no por la relación de cuentas a propósito: la
     * columna está desnormalizada para que la operación sobreviva al borrado
     * de una cuenta, igual que en el histórico.
     */
    public function operaciones(): HasMany
    {
        return $this->hasMany(ProvisioningOperation::class, 'gestor_user_id')->latest();
    }

    /**
     * Histórico de sus cuentas, del más reciente al más antiguo.
     *
     * Va por gestor_user_id y no por la relación de cuentas a propósito: la
     * columna está desnormalizada en la tabla para que el historial siga
     * disponible aunque la cuenta ya se haya borrado.
     */
    public function historial(): HasMany
    {
        return $this->hasMany(AccountStateLog::class, 'gestor_user_id')
            ->with(['subsystem', 'actor'])
            ->latest();
    }
}
