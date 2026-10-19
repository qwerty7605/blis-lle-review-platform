<?php

namespace App\Services;

use App\Models\User;

class RoleService
{
    public function canManageRooms(User $user): bool
    {
        return $this->isAdmin($user) || $this->isStaffMasterExaminer($user);
    }

    public function isAdmin(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN;
    }

    public function isStaffMasterExaminer(User $user): bool
    {
        return $user->role === User::ROLE_STAFF_MASTER_EXAMINER;
    }

    /**
     * Teaching -- rooms, exams, the Library, the Review Bot, reports -- is open
     * to teachers and to administrators.
     */
    public function canTeach(User $user): bool
    {
        return $this->canManageRooms($user);
    }

    /**
     * May this person act on something that belongs to $ownerId? A teacher
     * works with what they made; an administrator with everyone's, so the
     * school can step in for a teacher who is away. With no owner given:
     * do they see everybody's?
     */
    public function oversees(User $user, int|string|null $ownerId = null): bool
    {
        return $this->isAdmin($user)
            || ($ownerId !== null && (int) $ownerId === (int) $user->id);
    }
}
