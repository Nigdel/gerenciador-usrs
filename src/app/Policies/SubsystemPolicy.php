<?php

namespace App\Policies;

use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SubsystemPolicy
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
    public function view(User $user, Subsystem $subsystem): bool
    {
        return in_array($user->role, ['admin', 'operador', 'auditor']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Subsystem $subsystem): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Subsystem $subsystem): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can test connections.
     */
    public function testConnection(User $user, Subsystem $subsystem): bool
    {
        return in_array($user->role, ['admin', 'operador']);
    }
}
