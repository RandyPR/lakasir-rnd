<?php

namespace App\Policies\Tenants;

use App\Models\Tenants\Selling;
use App\Models\Tenants\User;

class SellingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('read selling');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Selling $selling): bool
    {
        return $user->can('read selling');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create selling');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Selling $selling): bool
    {
        return $user->can('update selling');
    }

    /**
     * Determine whether the user can move the model to the trash.
     */
    public function delete(User $user, Selling $selling): bool
    {
        return $user->can('delete selling');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete selling');
    }

    /**
     * Restoring needs the same permission as trashing. The owner (is_owner) passes every check (Gate::after).
     */
    public function restore(User $user, Selling $selling): bool
    {
        return $user->can('delete selling');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('delete selling');
    }

    /**
     * A sale is never deleted for good. Without these methods AuthServiceProvider's Gate::after would answer
     * "yes" for everyone, because 'forceDelete' is one of its default abilities; an explicit false is final.
     */
    public function forceDelete(User $user, Selling $selling): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
