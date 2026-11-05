<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hris.work_days' => [1, 2, 3, 4, 5],
            'hris.payroll.allow_open_month' => false,
        ]);

        // Rabu, 4 Nov 2026: bulan -2 = September, bulan lalu = Oktober
        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', config('app.timezone')));

        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_jumlah_karyawan_dan_akun_sesuai(): void
    {
        $this->assertSame(20, Employee::count());
        $this->assertSame(20, User::count());
    }

    public function test_payroll_dua_bulan_lalu_final_dan_bulan_lalu_draft(): void
    {
        // Kevin baru join Oktober, jadi tidak punya payroll September
        $this->assertSame(19, Payroll::where('period_month', 9)->where('status', 'final')->count());
        $this->assertSame(20, Payroll::where('period_month', 10)->where('status', 'draft')->count());
        $this->assertSame(39, Payroll::count());

        $this->assertSame(0, Payroll::where('net_salary', '<=', 0)->count());
    }

    public function test_karyawan_yang_join_di_tengah_bulan_digaji_proporsional(): void
    {
        $kevin = Employee::where('nip', 'EMP024')->firstOrFail();
        $payrolls = Payroll::where('employee_id', $kevin->id)->get();

        $this->assertCount(1, $payrolls);
        $this->assertSame(10, (int) $payrolls->first()->period_month);
        $this->assertGreaterThan(0, (float) $payrolls->first()->base_salary);
        $this->assertLessThan(9500000, (float) $payrolls->first()->base_salary);
    }

    public function test_pengajuan_cuti_ada_di_berbagai_status(): void
    {
        $this->assertSame(2, LeaveRequest::where('status', 'pending')->count());
        $this->assertSame(5, LeaveRequest::where('status', 'approved')->count());
        $this->assertSame(1, LeaveRequest::where('status', 'rejected')->count());

        // Putri sudah disetujui atasan dan sekarang menunggu HR
        $putri = LeaveRequest::whereHas('employee', fn ($q) => $q->where('nip', 'EMP014'))->firstOrFail();

        $this->assertSame('pending', $putri->status);
        $this->assertSame(2, (int) $putri->current_step);
    }

    public function test_absensi_ada_sampai_kemarin_dan_hari_ini_kosong(): void
    {
        $this->assertGreaterThan(0, Attendance::whereDate('date', '2026-11-03')->count());
        $this->assertSame(0, Attendance::whereDate('date', '2026-11-04')->count());
    }

    public function test_akun_demo_bisa_dipakai_login(): void
    {
        $this->postJson('/api/login', [
            'email' => 'dian@hris.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', 'manager');
    }

    public function test_seeder_aman_dijalankan_dua_kali(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(20, Employee::count());
        $this->assertSame(39, Payroll::count());
    }
}