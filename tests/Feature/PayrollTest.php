<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollComponent;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Semua tes memakai periode Oktober 2026 (22 hari kerja, bulan sudah berakhir)
 * dengan "hari ini" dikunci di Rabu, 4 November 2026.
 *
 * Gaji pokok seeder: Andi 10.000.000. Komponen seeder: transport 500.000 + makan 600.000
 * (pendapatan), BPJS 1% + JHT 2% dari gaji pokok (potongan).
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hris.work_days' => [1, 2, 3, 4, 5],
            'hris.work_end' => '17:00',
            'hris.payroll.allow_open_month' => false,
            'hris.payroll.overtime_divisor' => 173,
            'hris.payroll.overtime_first_hour_multiplier' => 1.5,
            'hris.payroll.overtime_next_hour_multiplier' => 2,
            'hris.payroll.overtime_max_hours_per_day' => 4,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', config('app.timezone')));

        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- Helper ----------

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());
    }

    private function employeeId(string $nip): int
    {
        return Employee::where('nip', $nip)->value('id');
    }

    /** Tanggal hari kerja (Senin-Jumat) di Oktober 2026, mulai dari $mulai */
    private function hariKerja(string $mulai = '2026-10-01'): array
    {
        $dates = [];

        foreach (CarbonPeriod::create($mulai, '2026-10-31') as $day) {
            if ($day->isoWeekday() <= 5) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    /** Catat kehadiran penuh 08:00-17:00. $hanya = batasi jumlah hari pertama saja. */
    private function isiAbsensi(string $nip, string $mulai = '2026-10-01', ?int $hanya = null): void
    {
        $dates = $this->hariKerja($mulai);

        if ($hanya !== null) {
            $dates = array_slice($dates, 0, $hanya);
        }

        foreach ($dates as $date) {
            Attendance::create([
                'employee_id' => $this->employeeId($nip),
                'date' => $date,
                'clock_in' => '08:00:00',
                'clock_out' => '17:00:00',
                'status' => 'present',
            ]);
        }
    }

    private function ubahJamPulang(string $nip, string $date, string $jam): void
    {
        Attendance::where('employee_id', $this->employeeId($nip))
            ->whereDate('date', $date)
            ->update(['clock_out' => $jam]);
    }

    private function generate(string $as, array $body = [])
    {
        $this->loginAs($as);

        return $this->postJson('/api/payrolls/generate', array_merge(['month' => 10, 'year' => 2026], $body));
    }

    private function generateUntuk(string $nip)
    {
        return $this->generate('hr@hris.test', ['employee_id' => $this->employeeId($nip)]);
    }

    private function payrollOf(string $nip, int $month = 10, int $year = 2026): Payroll
    {
        return Payroll::with('items')
            ->where('employee_id', $this->employeeId($nip))
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->firstOrFail();
    }

    private function itemsOf(Payroll $payroll): array
    {
        return $payroll->items->pluck('amount', 'name')->map(fn ($v) => (float) $v)->all();
    }

    // ---------- Komponen gaji ----------

    public function test_komponen_gaji_dari_seeder_tampil_untuk_hr(): void
    {
        $this->loginAs('hr@hris.test');

        $this->getJson('/api/payroll-components')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'type', 'calc_type', 'value']]]);
    }

    public function test_hr_bisa_menambah_mengubah_dan_menghapus_komponen(): void
    {
        $this->loginAs('hr@hris.test');

        $id = $this->postJson('/api/payroll-components', [
            'name' => 'Bonus Kinerja',
            'type' => 'earning',
            'calc_type' => 'fixed',
            'value' => 250000,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/payroll-components/{$id}", ['value' => 300000])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bonus Kinerja');

        $this->assertEquals(300000, (float) PayrollComponent::find($id)->value);

        $this->deleteJson("/api/payroll-components/{$id}")->assertOk();
        $this->assertDatabaseMissing('payroll_components', ['id' => $id]);
    }

    public function test_komponen_persen_tidak_boleh_lebih_dari_100(): void
    {
        $this->loginAs('hr@hris.test');

        $this->postJson('/api/payroll-components', [
            'name' => 'Bonus Aneh',
            'type' => 'earning',
            'calc_type' => 'percent',
            'value' => 150,
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
    }

    public function test_karyawan_biasa_tidak_boleh_mengelola_komponen(): void
    {
        $this->loginAs('andi@hris.test');

        $this->getJson('/api/payroll-components')->assertForbidden();
        $this->postJson('/api/payroll-components', [
            'name' => 'X', 'type' => 'earning', 'calc_type' => 'fixed', 'value' => 1,
        ])->assertForbidden();
    }

    // ---------- Perhitungan gaji ----------

    public function test_karyawan_hadir_penuh_gajinya_dihitung_benar(): void
    {
        $this->isiAbsensi('EMP004');

        $this->generateUntuk('EMP004')
            ->assertOk()
            ->assertJsonPath('summary.created', 1);

        $payroll = $this->payrollOf('EMP004');

        $this->assertSame('draft', $payroll->status);
        $this->assertEquals(10000000, (float) $payroll->base_salary);
        $this->assertEquals(1100000, (float) $payroll->total_earnings);
        $this->assertEquals(300000, (float) $payroll->total_deductions);
        $this->assertEquals(10800000, (float) $payroll->net_salary);

        $this->assertEquals([
            'Tunjangan Transport' => 500000,
            'Tunjangan Makan' => 600000,
            'BPJS Kesehatan (1%)' => 100000,
            'BPJS JHT (2%)' => 200000,
        ], $this->itemsOf($payroll));
    }

    public function test_hari_absen_memotong_gaji_per_hari(): void
    {
        // Hadir 20 dari 22 hari kerja, 2 hari absen tanpa keterangan
        $this->isiAbsensi('EMP004', hanya: 20);

        $this->generateUntuk('EMP004')->assertOk();

        $payroll = $this->payrollOf('EMP004');
        $items = $this->itemsOf($payroll);

        // 10.000.000 / 22 hari x 2 hari = 909.091 (dibulatkan)
        $this->assertEquals(909091, $items['Potongan Absen (2 hari)']);
        $this->assertEquals(1209091, (float) $payroll->total_deductions);
        $this->assertEquals(9890909, (float) $payroll->net_salary);
    }

    public function test_hari_cuti_tidak_dihitung_sebagai_absen(): void
    {
        $this->isiAbsensi('EMP004', hanya: 20);

        foreach (array_slice($this->hariKerja(), 20) as $date) {
            Attendance::create([
                'employee_id' => $this->employeeId('EMP004'),
                'date' => $date,
                'status' => 'leave',
            ]);
        }

        $this->generateUntuk('EMP004')->assertOk();

        $payroll = $this->payrollOf('EMP004');

        $this->assertCount(4, $payroll->items); // tidak ada potongan absen
        $this->assertEquals(10800000, (float) $payroll->net_salary);
    }

    public function test_lembur_dihitung_per_jam_penuh(): void
    {
        $this->isiAbsensi('EMP004');
        $this->ubahJamPulang('EMP004', '2026-10-05', '20:30:00'); // 3 jam 30 menit -> 3 jam

        $this->generateUntuk('EMP004')->assertOk();

        $payroll = $this->payrollOf('EMP004');

        // 10.000.000 / 173 x (1,5 + 2 + 2) = 317.919
        $this->assertEquals(317919, $this->itemsOf($payroll)['Lembur (3 jam)']);
        $this->assertEquals(1417919, (float) $payroll->total_earnings);
        $this->assertEquals(11117919, (float) $payroll->net_salary);
    }

    public function test_lembur_dibatasi_maksimal_per_hari(): void
    {
        $this->isiAbsensi('EMP004');
        $this->ubahJamPulang('EMP004', '2026-10-05', '23:00:00'); // 6 jam, dihitung maksimal 4

        $this->generateUntuk('EMP004')->assertOk();

        // 10.000.000 / 173 x (1,5 + 2 + 2 + 2) = 433.526
        $this->assertEquals(433526, $this->itemsOf($this->payrollOf('EMP004'))['Lembur (4 jam)']);
    }

    public function test_pulang_kurang_dari_satu_jam_setelah_jam_kerja_bukan_lembur(): void
    {
        $this->isiAbsensi('EMP004');
        $this->ubahJamPulang('EMP004', '2026-10-05', '17:45:00');

        $this->generateUntuk('EMP004')->assertOk();

        $this->assertCount(4, $this->payrollOf('EMP004')->items);
    }

    public function test_karyawan_yang_join_di_tengah_bulan_digaji_proporsional(): void
    {
        $id = Employee::create([
            'department_id' => Department::where('name', 'IT')->value('id'),
            'position_id' => Position::where('name', 'Software Engineer')->value('id'),
            'nip' => 'EMP099',
            'full_name' => 'Karyawan Baru',
            'join_date' => '2026-10-12', // Senin, sisa 15 dari 22 hari kerja
            'base_salary' => 11000000,
            'status' => 'active',
        ])->id;

        foreach ($this->hariKerja('2026-10-12') as $date) {
            Attendance::create([
                'employee_id' => $id,
                'date' => $date,
                'clock_in' => '08:00:00',
                'clock_out' => '17:00:00',
                'status' => 'present',
            ]);
        }

        $this->generateUntuk('EMP099')->assertOk();

        $payroll = $this->payrollOf('EMP099');

        // 11.000.000 x 15 / 22 = 7.500.000. BPJS 1% = 75.000, JHT 2% = 150.000
        $this->assertEquals(7500000, (float) $payroll->base_salary);
        $this->assertEquals(225000, (float) $payroll->total_deductions);
        $this->assertEquals(8375000, (float) $payroll->net_salary);
    }

    // ---------- Generate, hitung ulang, finalisasi ----------

    public function test_generate_untuk_semua_karyawan_aktif(): void
    {
        $this->generate('hr@hris.test')
            ->assertOk()
            ->assertJsonPath('summary.created', 5)
            ->assertJsonPath('summary.updated', 0);

        $this->assertSame(5, Payroll::count());
    }

    public function test_karyawan_nonaktif_tidak_ikut_payroll(): void
    {
        Employee::where('nip', 'EMP005')->update(['status' => 'inactive']);

        $this->generate('hr@hris.test')
            ->assertOk()
            ->assertJsonPath('summary.created', 4);
    }

    public function test_hitung_ulang_draft_mengganti_item_lama_tanpa_duplikat(): void
    {
        $this->isiAbsensi('EMP004', hanya: 20);
        $this->generateUntuk('EMP004')->assertOk();

        $this->assertArrayHasKey('Potongan Absen (2 hari)', $this->itemsOf($this->payrollOf('EMP004')));

        // Dua hari terakhir ternyata sudah dilengkapi
        foreach (array_slice($this->hariKerja(), 20) as $date) {
            Attendance::create([
                'employee_id' => $this->employeeId('EMP004'),
                'date' => $date,
                'clock_in' => '08:00:00',
                'clock_out' => '17:00:00',
                'status' => 'present',
            ]);
        }

        $this->generateUntuk('EMP004')
            ->assertOk()
            ->assertJsonPath('summary.updated', 1)
            ->assertJsonPath('summary.created', 0);

        $payroll = $this->payrollOf('EMP004');

        $this->assertSame(1, Payroll::where('employee_id', $this->employeeId('EMP004'))->count());
        $this->assertCount(4, $payroll->items);
        $this->assertEquals(10800000, (float) $payroll->net_salary);
    }

    public function test_payroll_final_tidak_bisa_dihitung_ulang(): void
    {
        $this->isiAbsensi('EMP004', hanya: 20);
        $this->generateUntuk('EMP004')->assertOk();

        $this->postJson('/api/payrolls/finalize', ['month' => 10, 'year' => 2026])
            ->assertOk()
            ->assertJsonPath('finalized', 1);

        foreach (array_slice($this->hariKerja(), 20) as $date) {
            Attendance::create([
                'employee_id' => $this->employeeId('EMP004'),
                'date' => $date,
                'clock_in' => '08:00:00',
                'clock_out' => '17:00:00',
                'status' => 'present',
            ]);
        }

        $this->generateUntuk('EMP004')
            ->assertOk()
            ->assertJsonPath('summary.skipped_final', 1);

        $payroll = $this->payrollOf('EMP004');

        $this->assertSame('final', $payroll->status);
        $this->assertEquals(9890909, (float) $payroll->net_salary); // angka lama tidak berubah
    }

    public function test_finalisasi_tanpa_draft_ditolak(): void
    {
        $this->loginAs('hr@hris.test');

        $this->postJson('/api/payrolls/finalize', ['month' => 10, 'year' => 2026])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }

    public function test_draft_bisa_dihapus_tapi_yang_final_tidak(): void
    {
        $this->isiAbsensi('EMP004');
        $this->generateUntuk('EMP004')->assertOk();

        $id = $this->payrollOf('EMP004')->id;

        $this->postJson('/api/payrolls/finalize', ['month' => 10, 'year' => 2026])->assertOk();
        $this->deleteJson("/api/payrolls/{$id}")->assertUnprocessable();

        Payroll::whereKey($id)->update(['status' => 'draft']);

        $this->deleteJson("/api/payrolls/{$id}")->assertOk();
        $this->assertDatabaseMissing('payrolls', ['id' => $id]);
    }

    // ---------- Validasi periode ----------

    public function test_periode_masa_depan_dan_bulan_berjalan_ditolak(): void
    {
        $this->generate('hr@hris.test', ['month' => 12])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');

        // November 2026 belum berakhir (hari ini 4 November)
        $this->generate('hr@hris.test', ['month' => 11])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }

    public function test_bulan_berjalan_boleh_dihitung_kalau_diizinkan_lewat_config(): void
    {
        config(['hris.payroll.allow_open_month' => true]);

        $this->generate('hr@hris.test', [
            'month' => 11,
            'employee_id' => $this->employeeId('EMP004'),
        ])->assertOk();

        $payroll = $this->payrollOf('EMP004', 11);

        // Absen dihitung sampai hari ini saja: 3 hari kerja (2, 3, 4 Nov), tanpa absensi sama sekali
        // 10.000.000 / 21 hari kerja bulan November x 3 = 1.428.571
        $this->assertEquals(10000000, (float) $payroll->base_salary);
        $this->assertEquals(1428571, $this->itemsOf($payroll)['Potongan Absen (3 hari)']);
    }

    public function test_validasi_input_generate(): void
    {
        $this->generate('hr@hris.test', ['month' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');

        $this->loginAs('hr@hris.test');

        $this->postJson('/api/payrolls/generate', ['month' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $this->generate('hr@hris.test', ['employee_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    // ---------- Hak akses & daftar ----------

    public function test_hanya_hr_yang_boleh_mengelola_payroll(): void
    {
        $this->generate('andi@hris.test')->assertForbidden();
        $this->generate('manager@hris.test')->assertForbidden();

        $this->loginAs('andi@hris.test');
        $this->getJson('/api/payrolls')->assertForbidden();
        $this->getJson('/api/payrolls/export?month=10&year=2026')->assertForbidden();
        $this->postJson('/api/payrolls/finalize', ['month' => 10, 'year' => 2026])->assertForbidden();
    }

    public function test_daftar_payroll_hr_punya_filter_dan_total(): void
    {
        $this->generate('hr@hris.test')->assertOk();

        $this->loginAs('hr@hris.test');

        $this->getJson('/api/payrolls?month=10&year=2026')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'status', 'net_salary', 'employee' => ['nip', 'full_name']]],
                'totals' => ['base_salary', 'total_earnings', 'total_deductions', 'net_salary'],
            ]);

        $this->getJson('/api/payrolls?status=final')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/payrolls?employee_id='.$this->employeeId('EMP004'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.nip', 'EMP004');
    }

    public function test_hr_bisa_melihat_detail_payroll_beserta_itemnya(): void
    {
        $this->isiAbsensi('EMP004');
        $this->generateUntuk('EMP004')->assertOk();

        $id = $this->payrollOf('EMP004')->id;

        $this->loginAs('hr@hris.test');

        $this->getJson("/api/payrolls/{$id}")
            ->assertOk()
            ->assertJsonPath('data.employee.nip', 'EMP004')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(4, 'data.items');
    }

    public function test_karyawan_hanya_melihat_slip_final_miliknya(): void
    {
        $this->generate('hr@hris.test')->assertOk();

        $andiId = $this->payrollOf('EMP004')->id;
        $rinaId = $this->payrollOf('EMP005')->id;

        // Selama masih draft, slip belum terlihat oleh karyawan
        $this->loginAs('andi@hris.test');
        $this->getJson('/api/payrolls/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/payrolls/{$andiId}")->assertForbidden();

        $this->loginAs('hr@hris.test');
        $this->postJson('/api/payrolls/finalize', ['month' => 10, 'year' => 2026])->assertOk();

        $this->loginAs('andi@hris.test');
        $this->getJson('/api/payrolls/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.nip', 'EMP004');

        $this->getJson("/api/payrolls/{$andiId}")->assertOk();
        $this->getJson("/api/payrolls/{$rinaId}")->assertForbidden(); // slip orang lain
    }

    // ---------- PDF dan Excel ----------

    public function test_slip_gaji_pdf_bisa_diunduh(): void
    {
        $this->isiAbsensi('EMP004');
        $this->generateUntuk('EMP004')->assertOk();

        $id = $this->payrollOf('EMP004')->id;

        $response = $this->get("/api/payrolls/{$id}/pdf");

        $response->assertOk()->assertDownload('slip-gaji-EMP004-2026-10.pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_karyawan_hanya_boleh_mengunduh_pdf_slip_final_miliknya(): void
    {
        $this->isiAbsensi('EMP004');
        $this->generateUntuk('EMP004')->assertOk();

        $id = $this->payrollOf('EMP004')->id;

        $this->loginAs('andi@hris.test');
        $this->get("/api/payrolls/{$id}/pdf")->assertForbidden(); // masih draft

        $this->loginAs('hr@hris.test');
        $this->postJson('/api/payrolls/finalize', ['month' => 10, 'year' => 2026])->assertOk();

        $this->loginAs('andi@hris.test');
        $this->get("/api/payrolls/{$id}/pdf")->assertOk();

        $this->loginAs('rina@hris.test');
        $this->get("/api/payrolls/{$id}/pdf")->assertForbidden();
    }

    public function test_export_excel_periode_payroll(): void
    {
        $this->generate('hr@hris.test')->assertOk();

        $this->loginAs('hr@hris.test');

        $this->get('/api/payrolls/export?month=10&year=2026')
            ->assertOk()
            ->assertDownload('payroll-2026-10.xlsx');
    }

    public function test_export_excel_tanpa_data_ditolak(): void
    {
        $this->loginAs('hr@hris.test');

        $this->getJson('/api/payrolls/export?month=9&year=2026')->assertUnprocessable();
        $this->getJson('/api/payrolls/export')->assertUnprocessable()->assertJsonValidationErrors(['month', 'year']);
    }
}