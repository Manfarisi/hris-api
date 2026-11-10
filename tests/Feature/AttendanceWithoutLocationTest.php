<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceWithoutLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hris.work_start' => '08:00',
            'hris.work_end' => '17:00',
            'hris.late_tolerance_minutes' => 0,
            'hris.work_days' => [1, 2, 3, 4, 5],
            'hris.attendance.require_location' => false,
        ]);

        $this->seed();
        Sanctum::actingAs(User::where('email', 'andi@hris.test')->firstOrFail());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Kunci waktu sekarang. Default: Rabu 4 Nov 2026 (hari kerja) */
    private function at(string $time, string $date = '2026-11-04'): void
    {
        Carbon::setTestNow(Carbon::parse("{$date} {$time}", config('app.timezone')));
    }

    public function test_status_hari_ini_memberi_tahu_lokasi_tidak_wajib(): void
    {
        $this->at('07:00');

        $this->getJson('/api/attendance/today')
            ->assertOk()
            ->assertJsonPath('location_required', false);
    }

    public function test_clock_in_tanpa_koordinat_berhasil_dan_jam_tercatat(): void
    {
        $this->at('07:30');

        $this->postJson('/api/attendance/clock-in')
            ->assertCreated()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.clock_in', '07:30:00')
            ->assertJsonPath('location', null);

        $this->assertDatabaseHas('attendances', ['in_lat' => null, 'in_lng' => null]);
    }

    public function test_telat_tetap_dihitung_dari_jam_server(): void
    {
        $this->at('09:00');

        $this->postJson('/api/attendance/clock-in')
            ->assertCreated()
            ->assertJsonPath('data.status', 'late');
    }

    public function test_koordinat_yang_dikirim_klien_tidak_disimpan(): void
    {
        $this->at('07:30');

        // Jauh dari kantor, tetap diterima, dan koordinatnya diabaikan
        $this->postJson('/api/attendance/clock-in', ['latitude' => -6.2, 'longitude' => 106.9])
            ->assertCreated();

        $this->assertDatabaseHas('attendances', ['in_lat' => null, 'in_lng' => null]);
    }

    public function test_clock_out_tanpa_koordinat_menghitung_lama_kerja(): void
    {
        $this->at('07:30');
        $this->postJson('/api/attendance/clock-in')->assertCreated();

        $this->at('17:00');
        $this->postJson('/api/attendance/clock-out')
            ->assertOk()
            ->assertJsonPath('data.clock_out', '17:00:00')
            ->assertJsonPath('data.work_minutes', 570)
            ->assertJsonPath('location', null);

        $this->assertDatabaseHas('attendances', ['out_lat' => null, 'out_lng' => null]);
    }

    public function test_aturan_lain_tetap_berlaku(): void
    {
        $this->at('07:30');

        $this->postJson('/api/attendance/clock-in')->assertCreated();
        $this->postJson('/api/attendance/clock-in')->assertUnprocessable();

        $this->at('07:30', '2026-11-08'); // Minggu
        $this->postJson('/api/attendance/clock-in')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_saat_lokasi_diaktifkan_koordinat_kembali_wajib(): void
    {
        config(['hris.attendance.require_location' => true]);
        $this->at('07:30');

        $this->getJson('/api/attendance/today')->assertJsonPath('location_required', true);

        $this->postJson('/api/attendance/clock-in')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }
}