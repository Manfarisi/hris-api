<?php

namespace Database\Seeders;

use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $positions = [
            'HR Manager',
            'HR Staff',
            'IT Manager',
            'Software Engineer',
            'Finance Staff',
            'Marketing Staff',
            'Operations Staff',
        ];

        foreach ($positions as $name) {
            Position::firstOrCreate(['name' => $name]);
        }
    }
}