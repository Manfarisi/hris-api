<?php

return [
    'company_name' => env('HRIS_COMPANY_NAME', 'PT Contoh Sejahtera'),

    // Jam masuk & pulang kantor
    'work_start' => env('HRIS_WORK_START', '08:00'),
    'work_end' => env('HRIS_WORK_END', '17:00'),

    // Toleransi keterlambatan (menit) setelah jam masuk
    'late_tolerance_minutes' => (int) env('HRIS_LATE_TOLERANCE', 0),

    // Hari kerja (ISO): 1 = Senin ... 7 = Minggu
    'work_days' => [1, 2, 3, 4, 5],

    'attendance' => [
        // true  = clock in/out hanya sah di dalam radius kantor (koordinat wajib dikirim)
        // false = hanya jam yang dicatat, koordinat tidak diminta dan tidak disimpan
        'require_location' => (bool) env('HRIS_REQUIRE_LOCATION', false),
    ],

    'payroll' => [
        // Upah per jam lembur = gaji pokok / pembagi ini
        'overtime_divisor' => 173,

        // Pengali upah per jam: jam lembur pertama, lalu jam-jam berikutnya
        'overtime_first_hour_multiplier' => 1.5,
        'overtime_next_hour_multiplier' => 2,

        // Batas jam lembur yang dihitung per hari
        'overtime_max_hours_per_day' => 4,

        // false = payroll hanya untuk bulan yang sudah berakhir
        'allow_open_month' => (bool) env('HRIS_PAYROLL_ALLOW_OPEN_MONTH', false),
    ],
];