<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Can the user open the users list?
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view users');
    }

    /**
     * Can the user view this user?
     */
    public function view(User $user, User $model): bool
    {
        return $user->can('view users');
    }

    /**
     * Can the user create users?
     */
    public function create(User $user): bool
    {
        return $user->can('create users');
    }

    /**
     * Can the user edit this user (e.g. change their role)?
     * A super-admin cannot be edited here.
     */
    public function update(User $user, User $model): bool
    {
        return $user->can('edit users')
            && ! $model->hasRole('super-admin');
    }

    /**
     * Can the user delete this user?
     * Nobody can delete themselves, and a super-admin cannot be deleted here.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->can('delete users')
            && $user->isNot($model)
            && ! $model->hasRole('super-admin');
    }
}
