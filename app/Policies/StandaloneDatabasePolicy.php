<?php

namespace App\Policies;

use App\Models\StandaloneDatabase;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StandaloneDatabasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, StandaloneDatabase $database): bool
    {
        return $user->canAccessTeam($database->team);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StandaloneDatabase $database): bool
    {
        return $user->canAccessTeam($database->team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StandaloneDatabase $database): bool
    {
        return $user->id === $database->team->owner_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, StandaloneDatabase $standaloneDatabase): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, StandaloneDatabase $standaloneDatabase): bool
    {
        return false;
    }
}
