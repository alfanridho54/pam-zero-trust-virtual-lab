<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vm;
use Illuminate\Auth\Access\Response;

class VmPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($this->roleFor($user), ['admin', 'guru', 'student'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Vm $vm): bool
    {
        return $this->canSupervise($user) || $vm->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->roleFor($user) === 'student' || $this->canSupervise($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Vm $vm): bool
    {
        return ! $vm->isProtectedVm() && ($this->canSupervise($user) || $vm->user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Vm $vm): bool
    {
        return ! $vm->isProtectedVm() && ($this->canSupervise($user) || $vm->user_id === $user->id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Vm $vm): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Vm $vm): bool
    {
        return false;
    }

    private function canSupervise(User $user): bool
    {
        return in_array($this->roleFor($user), ['admin', 'guru'], true);
    }

    private function roleFor(User $user): string
    {
        return match ($user->role) {
            'admin' => 'admin',
            'guru', 'teacher' => 'guru',
            'student', 'mahasiswa' => 'student',
            default => 'guest',
        };
    }
}
