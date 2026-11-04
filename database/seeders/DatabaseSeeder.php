<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            OfficeLocationSeeder::class,
            UserSeeder::class,
        ]);
    }
}