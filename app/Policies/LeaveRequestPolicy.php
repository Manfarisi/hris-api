<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    /** HR lihat semua, atasan lihat bawahannya, karyawan lihat miliknya sendiri */
    public function view(User $user, LeaveRequest $leave): bool
    {
        if ($user->isHr()) {
            return true;
        }

        $own = $user->employee;

        if (! $own) {
            return false;
        }

        if ((int) $leave->employee_id === (int) $own->id) {
            return true;
        }

        return (int) $leave->employee->manager_id === (int) $own->id;
    }

    /**
     * Boleh approve/reject SEKARANG?
     * Tahap 1 = atasan langsung pengaju, tahap 2 = HR. Tidak boleh memproses pengajuan sendiri.
     */
    public function act(User $user, LeaveRequest $leave): bool
    {
        if ($leave->status !== 'pending') {
            return false;
        }

        $own = $user->employee;

        if ($own && (int) $own->id === (int) $leave->employee_id) {
            return false;
        }

        return match ((int) $leave->current_step) {
            1 => $leave->employee->manager?->user_id === $user->id,
            2 => $user->isHr(),
            default => false,
        };
    }

    /** Hanya pengaju yang boleh membatalkan */
    public function cancel(User $user, LeaveRequest $leave): bool
    {
        $own = $user->employee;

        return $own !== null && (int) $own->id === (int) $leave->employee_id;
    }
}