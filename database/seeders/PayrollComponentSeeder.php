<?php

namespace Database\Seeders;

use App\Models\PayrollComponent;
use Illuminate\Database\Seeder;

class PayrollComponentSeeder extends Seeder
{
    public function run(): void
    {
        $components = [
            ['name' => 'Tunjangan Transport', 'type' => 'earning', 'calc_type' => 'fixed', 'value' => 500000],
            ['name' => 'Tunjangan Makan', 'type' => 'earning', 'calc_type' => 'fixed', 'value' => 600000],
            ['name' => 'BPJS Kesehatan (1%)', 'type' => 'deduction', 'calc_type' => 'percent', 'value' => 1],
            ['name' => 'BPJS JHT (2%)', 'type' => 'deduction', 'calc_type' => 'percent', 'value' => 2],
        ];

        foreach ($components as $component) {
            PayrollComponent::updateOrCreate(['name' => $component['name']], $component);
        }
    }
}