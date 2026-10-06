<?php

namespace App\Policies;

use App\Models\GestorUser;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GestorUserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'operador', 'auditor']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, GestorUser $gestorUser): bool
    {
        return in_array($user->role, ['admin', 'operador', 'auditor']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, GestorUser $gestorUser): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GestorUser $gestorUser): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can suspend accounts.
     */
    public function suspend(User $user, GestorUser $gestorUser): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }

    /**
     * Determine whether the user can reset passwords.
     */
    public function resetPassword(User $user, GestorUser $gestorUser): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }

    /**
     * Determine whether the user can give the user an offboarding (baja).
     *
     * Exige admin, igual que eliminar. Deshabilitar el acceso de una persona
     * en todos los subsistemas es una acción de RRHH desde el punto de vista
     * de quien la pide: el operador que la ejecute no debería poder hacerlo.
     */
    public function offboard(User $user, GestorUser $gestorUser): bool
    {
        return $user->role === 'admin';
    }
}
