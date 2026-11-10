<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    // Koordinat kantor hasil OfficeLocationSeeder
    private const OFFICE = ['latitude' => -6.1753924, 'longitude' => 106.8271528];
    private const FAR_AWAY = ['latitude' => -6.2, 'longitude' => 106.9];

    protected function setUp(): void
    {
        parent::setUp();

        // Jangan bergantung pada isi .env
        config([
            'hris.work_start' => '08:00',
            'hris.work_end' => '17:00',
            'hris.late_tolerance_minutes' => 0,
            'hris.work_days' => [1, 2, 3, 4, 5],
            'hris.attendance.require_location' => true,
        ]);

        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());
    }

    /** Kunci waktu sekarang. Default: Rabu 4 Nov 2026 (hari kerja) */
    private function at(string $time, string $date = '2026-11-04'): void
    {
        Carbon::setTestNow(Carbon::parse("{$date} {$time}", config('app.timezone')));
    }

    // ---------- Clock in ----------

    public function test_status_hari_ini_kosong_sebelum_clock_in(): void
    {
        $this->at('07:00');
        $this->loginAs('andi@hris.test');

        $this->getJson('/api/attendance/today')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('is_working_day', true);
    }

    public function test_clock_in_di_luar_radius_ditolak(): void
    {
        $this->at('07:30');
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-in', self::FAR_AWAY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_clock_in_sebelum_jam_masuk_berstatus_present(): void
    {
        $this->at('07:30');
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-in', self::OFFICE)
            ->assertCreated()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.clock_in', '07:30:00')
            ->assertJsonPath('location.office', 'Kantor Pusat');
    }

    public function test_clock_in_setelah_jam_masuk_berstatus_late(): void
    {
        $this->at('09:00');
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-in', self::OFFICE)
            ->assertCreated()
            ->assertJsonPath('data.status', 'late');
    }

    public function test_toleransi_keterlambatan_dipakai(): void
    {
        config(['hris.late_tolerance_minutes' => 15]);

        $this->at('08:10');
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-in', self::OFFICE)
            ->assertCreated()
            ->assertJsonPath('data.status', 'present');
    }

    public function test_tidak_bisa_clock_in_dua_kali_di_hari_yang_sama(): void
    {
        $this->at('07:30');
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-in', self::OFFICE)->assertCreated();
        $this->postJson('/api/attendance/clock-in', self::OFFICE)->assertUnprocessable();

        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_clock_in_tanpa_koordinat_ditolak(): void
    {
        $this->at('07:30');
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-in')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_clock_in_di_hari_libur_ditolak(): void
    {
        $this->at('07:30', '2026-11-08'); // Minggu
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-in', self::OFFICE)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    // ---------- Clock out ----------

    public function test_clock_out_menghitung_lama_kerja(): void
    {
        $this->loginAs('andi@hris.test');

        $this->at('07:30');
        $this->postJson('/api/attendance/clock-in', self::OFFICE)->assertCreated();

        $this->at('17:00');
        $this->postJson('/api/attendance/clock-out', self::OFFICE)
            ->assertOk()
            ->assertJsonPath('data.clock_out', '17:00:00')
            ->assertJsonPath('data.work_minutes', 570);
    }

    public function test_clock_out_tanpa_clock_in_ditolak(): void
    {
        $this->at('17:00');
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-out', self::OFFICE)->assertUnprocessable();
    }

    public function test_tidak_bisa_clock_out_dua_kali(): void
    {
        $this->loginAs('andi@hris.test');

        $this->at('07:30');
        $this->postJson('/api/attendance/clock-in', self::OFFICE)->assertCreated();

        $this->at('17:00');
        $this->postJson('/api/attendance/clock-out', self::OFFICE)->assertOk();
        $this->postJson('/api/attendance/clock-out', self::OFFICE)->assertUnprocessable();
    }

    public function test_riwayat_sendiri_berisi_ringkasan_bulan_ini(): void
    {
        $this->at('09:00');
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/attendance/clock-in', self::OFFICE)->assertCreated();

        $this->getJson('/api/attendance/me')
            ->assertOk()
            ->assertJsonPath('summary.attended_days', 1)
            ->assertJsonPath('summary.late_days', 1)
            ->assertJsonPath('summary.on_time_days', 0)
            ->assertJsonCount(1, 'data');
    }

    // ---------- Laporan ----------

    private function andiDanRinaClockIn(): void
    {
        $this->at('07:30');

        foreach (['andi@hris.test', 'rina@hris.test'] as $email) {
            $this->loginAs($email);
            $this->postJson('/api/attendance/clock-in', self::OFFICE)->assertCreated();
        }
    }

    public function test_hr_melihat_semua_absensi_beserta_koordinat(): void
    {
        $this->andiDanRinaClockIn();
        $this->loginAs('hr@hris.test');

        $this->getJson('/api/attendances')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => ['*' => ['in_location' => ['latitude', 'longitude']]]]);
    }

    public function test_manager_melihat_absensi_bawahan_tanpa_koordinat(): void
    {
        $this->andiDanRinaClockIn();

        // Absensi orang di luar tim manager tidak boleh ikut tampil
        $this->loginAs('hr@hris.test');
        $this->postJson('/api/attendance/clock-in', self::OFFICE)->assertCreated();

        $this->loginAs('manager@hris.test');

        $this->getJson('/api/attendances')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.in_location');
    }

    public function test_karyawan_biasa_tidak_boleh_melihat_laporan(): void
    {
        $this->loginAs('andi@hris.test');

        $this->getJson('/api/attendances')->assertForbidden();
        $this->getJson('/api/attendances/recap')->assertForbidden();
    }

    public function test_filter_status_dan_rentang_tanggal_laporan(): void
    {
        $this->andiDanRinaClockIn();

        // Satu data lagi: Andi telat di hari sebelumnya
        Attendance::create([
            'employee_id' => Employee::where('nip', 'EMP004')->value('id'),
            'date' => '2026-11-03',
            'clock_in' => '09:15:00',
            'status' => 'late',
        ]);

        $this->loginAs('hr@hris.test');

        $this->getJson('/api/attendances?status=late&from=2026-11-01&to=2026-11-04')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'late');

        $this->getJson('/api/attendances?from=2026-11-01&to=2026-11-04')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_rekap_bulanan_manager_hanya_berisi_bawahan(): void
    {
        $this->andiDanRinaClockIn();
        $this->loginAs('manager@hris.test');

        $response = $this->getJson('/api/attendances/recap?month=11&year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $row) {
            $this->assertSame(1, $row['attended_days']);
            $this->assertSame(1, $row['on_time_days']);
        }
    }

    public function test_rekap_bulanan_hr_berisi_semua_karyawan_aktif(): void
    {
        $this->at('07:30');
        $this->loginAs('hr@hris.test');

        $this->getJson('/api/attendances/recap')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }
}