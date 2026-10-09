<?php

namespace App\Policies;

use App\Models\ProvisioningOperation;
use App\Models\User;

class ProvisioningOperationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'operador', 'auditor']);
    }

    public function view(User $user, ProvisioningOperation $provisioningOperation): bool
    {
        return in_array($user->role, ['admin', 'operador', 'auditor']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ProvisioningOperation $provisioningOperation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ProvisioningOperation $provisioningOperation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ProvisioningOperation $provisioningOperation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ProvisioningOperation $provisioningOperation): bool
    {
        return false;
    }
}
