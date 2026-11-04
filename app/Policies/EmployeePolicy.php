<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    /** Siapa yang boleh lihat daftar karyawan */
    public function viewAny(User $user): bool
    {
        return $user->isHr()
            || ($user->hasRole(User::ROLE_MANAGER) && $user->employee !== null);
    }

    /** HR lihat semua, manager lihat bawahannya + dirinya, karyawan lihat dirinya sendiri */
    public function view(User $user, Employee $employee): bool
    {
        if ($user->isHr()) {
            return true;
        }

        $own = $user->employee;

        if (! $own) {
            return false;
        }

        if ($own->id === $employee->id) {
            return true;
        }

        return $user->hasRole(User::ROLE_MANAGER) && $employee->manager_id === $own->id;
    }

    public function create(User $user): bool
    {
        return $user->isHr();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->isHr();
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->isHr();
    }
}