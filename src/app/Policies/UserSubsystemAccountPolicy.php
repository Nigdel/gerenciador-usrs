<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserSubsystemAccount;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserSubsystemAccountPolicy
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
    public function view(User $user, UserSubsystemAccount $account): bool
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
    public function update(User $user, UserSubsystemAccount $account): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, UserSubsystemAccount $account): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can suspend the account.
     */
    public function suspend(User $user, UserSubsystemAccount $account): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }

    /**
     * Determine whether the user can reactivate the account.
     */
    public function reactivate(User $user, UserSubsystemAccount $account): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }

    /**
     * Determine whether the user can disable the account.
     */
    public function disable(User $user, UserSubsystemAccount $account): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }
}
