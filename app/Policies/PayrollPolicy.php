<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\User;

class PayrollPolicy
{
    /** HR lihat semua. Karyawan hanya boleh melihat slip miliknya yang sudah final. */
    public function view(User $user, Payroll $payroll): bool
    {
        if ($user->isHr()) {
            return true;
        }

        $own = $user->employee;

        return $own !== null
            && (int) $own->id === (int) $payroll->employee_id
            && $payroll->status === 'final';
    }
}