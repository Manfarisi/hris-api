<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Cuti Tahunan', 'default_quota' => 12],
            ['name' => 'Cuti Sakit', 'default_quota' => 12],
            ['name' => 'Cuti Menikah', 'default_quota' => 3],
        ];

        foreach ($types as $type) {
            LeaveType::updateOrCreate(['name' => $type['name']], $type);
        }
    }
}