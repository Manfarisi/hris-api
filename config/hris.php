<?php

return [
    // Jam masuk & pulang kantor
    'work_start' => env('HRIS_WORK_START', '08:00'),
    'work_end' => env('HRIS_WORK_END', '17:00'),

    // Toleransi keterlambatan (menit) setelah jam masuk
    'late_tolerance_minutes' => (int) env('HRIS_LATE_TOLERANCE', 0),

    // Hari kerja (ISO): 1 = Senin ... 7 = Minggu
    'work_days' => [1, 2, 3, 4, 5],
];