<?php

namespace Database\Seeders;

use App\Models\OfficeLocation;
use Illuminate\Database\Seeder;

class OfficeLocationSeeder extends Seeder
{
    public function run(): void
    {
        // Ganti koordinat ini dengan lokasi kamu biar gampang ngetes absensi nanti
        OfficeLocation::updateOrCreate(
            ['name' => 'Kantor Pusat'],
            [
                'latitude' => -6.1753924,
                'longitude' => 106.8271528,
                'radius_meters' => 100,
            ]
        );
    }
}