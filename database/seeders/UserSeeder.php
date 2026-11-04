<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Urutan penting: atasan harus dibuat sebelum bawahannya
        $people = [
            [
                'name' => 'Super Admin',
                'email' => 'admin@hris.test',
                'role' => User::ROLE_SUPER_ADMIN,
                'nip' => 'EMP001',
                'department' => 'Human Resources',
                'position' => 'HR Manager',
                'manager_email' => null,
                'salary' => 15000000,
            ],
            [
                'name' => 'Sinta Maharani',
                'email' => 'hr@hris.test',
                'role' => User::ROLE_HR,
                'nip' => 'EMP002',
                'department' => 'Human Resources',
                'position' => 'HR Staff',
                'manager_email' => null,
                'salary' => 8000000,
            ],
            [
                'name' => 'Budi Santoso',
                'email' => 'manager@hris.test',
                'role' => User::ROLE_MANAGER,
                'nip' => 'EMP003',
                'department' => 'IT',
                'position' => 'IT Manager',
                'manager_email' => null,
                'salary' => 18000000,
            ],
            [
                'name' => 'Andi Pratama',
                'email' => 'andi@hris.test',
                'role' => User::ROLE_EMPLOYEE,
                'nip' => 'EMP004',
                'department' => 'IT',
                'position' => 'Software Engineer',
                'manager_email' => 'manager@hris.test',
                'salary' => 10000000,
            ],
            [
                'name' => 'Rina Wulandari',
                'email' => 'rina@hris.test',
                'role' => User::ROLE_EMPLOYEE,
                'nip' => 'EMP005',
                'department' => 'IT',
                'position' => 'Software Engineer',
                'manager_email' => 'manager@hris.test',
                'salary' => 9500000,
            ],
        ];

        $employeeIdByEmail = [];

        foreach ($people as $person) {
            $user = User::updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => 'password', // khusus data demo, otomatis di-hash oleh cast
                    'role' => $person['role'],
                ]
            );

            $employee = Employee::updateOrCreate(
                ['nip' => $person['nip']],
                [
                    'user_id' => $user->id,
                    'full_name' => $person['name'],
                    'department_id' => Department::where('name', $person['department'])->value('id'),
                    'position_id' => Position::where('name', $person['position'])->value('id'),
                    'manager_id' => $person['manager_email']
                        ? $employeeIdByEmail[$person['manager_email']]
                        : null,
                    'join_date' => '2024-01-15',
                    'base_salary' => $person['salary'],
                    'status' => 'active',
                ]
            );

            $employeeIdByEmail[$person['email']] = $employee->id;

            // Saldo cuti tahun berjalan untuk setiap jenis cuti
            foreach (LeaveType::all() as $leaveType) {
                LeaveBalance::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveType->id,
                        'year' => now()->year,
                    ],
                    [
                        'quota' => $leaveType->default_quota,
                        'used' => 0,
                    ]
                );
            }
        }
    }
}