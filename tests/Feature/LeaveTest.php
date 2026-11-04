<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['hris.work_days' => [1, 2, 3, 4, 5]]);

        // Rabu, 4 Nov 2026. Senin depannya = 9 Nov, Sabtu = 7 Nov, Minggu = 8 Nov
        $this->at('2026-11-04 10:00:00');

        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- Helper ----------

    private function at(string $datetime): void
    {
        Carbon::setTestNow(Carbon::parse($datetime, config('app.timezone')));
    }

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());
    }

    private function tipe(string $name): int
    {
        return LeaveType::where('name', $name)->value('id');
    }

    private function employeeId(string $nip): int
    {
        return Employee::where('nip', $nip)->value('id');
    }

    private function saldoTerpakai(string $nip, string $tipe): int
    {
        return (int) LeaveBalance::where('employee_id', $this->employeeId($nip))
            ->where('leave_type_id', $this->tipe($tipe))
            ->where('year', 2026)
            ->value('used');
    }

    /** Ajukan cuti. Default: Cuti Tahunan Senin-Rabu depan (3 hari kerja) */
    private function ajukan(string $email, array $override = [])
    {
        $this->loginAs($email);

        return $this->postJson('/api/leave-requests', array_merge([
            'leave_type_id' => $this->tipe('Cuti Tahunan'),
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-11',
            'reason' => 'Acara keluarga',
        ], $override));
    }

    private function aksi(string $email, int $id, string $action, array $body = [])
    {
        $this->loginAs($email);

        return $this->postJson("/api/leave-requests/{$id}/{$action}", $body);
    }

    /** Andi ajukan, Budi (atasan) setuju, Sinta (HR) setuju. Mengembalikan ID pengajuan. */
    private function cutiAndiDisetujui(): int
    {
        $id = $this->ajukan('andi@hris.test')->assertCreated()->json('data.id');

        $this->aksi('manager@hris.test', $id, 'approve')->assertOk();
        $this->aksi('hr@hris.test', $id, 'approve')->assertOk();

        return $id;
    }

    // ---------- Jenis cuti & saldo ----------

    public function test_semua_user_bisa_melihat_jenis_cuti(): void
    {
        $this->loginAs('andi@hris.test');

        $this->getJson('/api/leave-types')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_saldo_cuti_sendiri_tampil_lengkap(): void
    {
        $this->loginAs('andi@hris.test');

        $this->getJson('/api/leave-balances/me')
            ->assertOk()
            ->assertJsonPath('year', 2026)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.quota', 12)
            ->assertJsonPath('data.0.remaining', 12)
            ->assertJsonPath('data.0.available', 12);
    }

    // ---------- Pengajuan ----------

    public function test_pengajuan_cuti_berhasil_dan_menunggu_atasan(): void
    {
        $this->ajukan('andi@hris.test')
            ->assertCreated()
            ->assertJsonPath('data.total_days', 3)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_step', 1)
            ->assertJsonPath('data.waiting_for', 'atasan')
            ->assertJsonCount(2, 'data.approvals');
    }

    public function test_akhir_pekan_tidak_dihitung_sebagai_hari_cuti(): void
    {
        // Jumat 6 Nov sampai Senin 9 Nov = 2 hari kerja
        $this->ajukan('andi@hris.test', ['start_date' => '2026-11-06', 'end_date' => '2026-11-09'])
            ->assertCreated()
            ->assertJsonPath('data.total_days', 2);
    }

    public function test_rentang_yang_hanya_akhir_pekan_ditolak(): void
    {
        $this->ajukan('andi@hris.test', ['start_date' => '2026-11-07', 'end_date' => '2026-11-08'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_tanggal_mulai_di_masa_lalu_ditolak(): void
    {
        $this->ajukan('andi@hris.test', ['start_date' => '2026-11-03', 'end_date' => '2026-11-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_tanggal_akhir_sebelum_tanggal_mulai_ditolak(): void
    {
        $this->ajukan('andi@hris.test', ['start_date' => '2026-11-11', 'end_date' => '2026-11-09'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    public function test_cuti_lintas_tahun_ditolak(): void
    {
        $this->ajukan('andi@hris.test', ['start_date' => '2026-12-30', 'end_date' => '2027-01-04'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    public function test_jenis_cuti_yang_tidak_ada_ditolak(): void
    {
        $this->ajukan('andi@hris.test', ['leave_type_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type_id');
    }

    public function test_saldo_tidak_cukup_ditolak(): void
    {
        // Cuti Menikah kuotanya 3 hari, di sini diminta 4 hari kerja
        $this->ajukan('andi@hris.test', [
            'leave_type_id' => $this->tipe('Cuti Menikah'),
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-12',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_pengajuan_yang_masih_menunggu_ikut_mengurangi_saldo_tersedia(): void
    {
        $menikah = $this->tipe('Cuti Menikah');

        $this->ajukan('andi@hris.test', [
            'leave_type_id' => $menikah,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-11',
        ])->assertCreated();

        // Kuota 3 sudah "dipesan" pengajuan pertama
        $this->ajukan('andi@hris.test', [
            'leave_type_id' => $menikah,
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-17',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_pengajuan_yang_bertabrakan_tanggalnya_ditolak(): void
    {
        $this->ajukan('andi@hris.test')->assertCreated();

        $this->ajukan('andi@hris.test', ['start_date' => '2026-11-11', 'end_date' => '2026-11-12'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    // ---------- Alur persetujuan ----------

    public function test_alur_persetujuan_bertingkat_sampai_cuti_disetujui(): void
    {
        $id = $this->ajukan('andi@hris.test')->assertCreated()->json('data.id');

        // Tahap 1: atasan langsung
        $this->aksi('manager@hris.test', $id, 'approve', ['notes' => 'Silakan'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_step', 2)
            ->assertJsonPath('data.waiting_for', 'hr');

        // Saldo belum berkurang sebelum persetujuan terakhir
        $this->assertSame(0, $this->saldoTerpakai('EMP004', 'Cuti Tahunan'));

        // Tahap 2: HR
        $this->aksi('hr@hris.test', $id, 'approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.waiting_for', null);

        $this->assertSame(3, $this->saldoTerpakai('EMP004', 'Cuti Tahunan'));

        $tanggalCuti = Attendance::where('employee_id', $this->employeeId('EMP004'))
            ->where('status', 'leave')
            ->orderBy('date')
            ->get()
            ->map(fn($a) => $a->date->toDateString())
            ->all();

        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-11'], $tanggalCuti);
    }

    public function test_cuti_yang_disetujui_muncul_di_rekap_absensi(): void
    {
        $this->cutiAndiDisetujui();

        $this->loginAs('hr@hris.test');

        $response = $this->getJson('/api/attendances/recap?month=11&year=2026')->assertOk();

        $andi = collect($response->json('data'))->firstWhere('employee.nip', 'EMP004');

        $this->assertSame(3, $andi['leave_days']);
    }

    public function test_hanya_atasan_langsung_yang_boleh_menyetujui_tahap_1(): void
    {
        $id = $this->ajukan('andi@hris.test')->assertCreated()->json('data.id');

        $this->aksi('rina@hris.test', $id, 'approve')->assertForbidden();   // rekan satu tim
        $this->aksi('hr@hris.test', $id, 'approve')->assertForbidden();     // HR belum gilirannya
        $this->aksi('admin@hris.test', $id, 'approve')->assertForbidden();  // super admin juga
    }

    public function test_atasan_tidak_boleh_menyetujui_tahap_2(): void
    {
        $id = $this->ajukan('andi@hris.test')->assertCreated()->json('data.id');

        $this->aksi('manager@hris.test', $id, 'approve')->assertOk();
        $this->aksi('manager@hris.test', $id, 'approve')->assertForbidden();
    }

    public function test_pengajuan_yang_sudah_selesai_tidak_bisa_diproses_lagi(): void
    {
        $id = $this->cutiAndiDisetujui();

        $this->aksi('hr@hris.test', $id, 'approve')->assertForbidden();
        $this->aksi('hr@hris.test', $id, 'reject', ['notes' => 'Salah'])->assertForbidden();
    }

    public function test_pengaju_tanpa_atasan_langsung_ke_tahap_hr_dan_tidak_bisa_menyetujui_sendiri(): void
    {
        // Sinta (HR) tidak punya atasan, jadi hanya ada tahap HR
        $id = $this->ajukan('hr@hris.test')
            ->assertCreated()
            ->assertJsonPath('data.current_step', 2)
            ->assertJsonCount(1, 'data.approvals')
            ->json('data.id');

        $this->aksi('hr@hris.test', $id, 'approve')->assertForbidden();

        $this->aksi('admin@hris.test', $id, 'approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(3, $this->saldoTerpakai('EMP002', 'Cuti Tahunan'));
    }

    public function test_penolakan_wajib_catatan_dan_tidak_memotong_saldo(): void
    {
        $id = $this->ajukan('andi@hris.test')->assertCreated()->json('data.id');

        $this->aksi('manager@hris.test', $id, 'reject')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notes');

        $this->aksi('manager@hris.test', $id, 'reject', ['notes' => 'Ada rilis minggu depan'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonCount(1, 'data.approvals');

        $this->assertSame(0, $this->saldoTerpakai('EMP004', 'Cuti Tahunan'));
        $this->assertSame(0, Attendance::where('status', 'leave')->count());
    }

    public function test_saldo_dicek_ulang_saat_persetujuan_terakhir(): void
    {
        $id = $this->ajukan('andi@hris.test', [
            'leave_type_id' => $this->tipe('Cuti Menikah'),
        ])->assertCreated()->json('data.id');

        $this->aksi('manager@hris.test', $id, 'approve')->assertOk();

        // Seolah saldo sudah terpakai dari jalur lain sebelum HR sempat menyetujui
        LeaveBalance::where('employee_id', $this->employeeId('EMP004'))
            ->where('leave_type_id', $this->tipe('Cuti Menikah'))
            ->update(['used' => 1]);

        $this->aksi('hr@hris.test', $id, 'approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('balance');

        // Semuanya dibatalkan: pengajuan tetap pending dan saldo tidak berubah
        $this->assertSame('pending', LeaveRequest::find($id)->status);
        $this->assertSame(1, $this->saldoTerpakai('EMP004', 'Cuti Menikah'));
    }

    // ---------- Pembatalan ----------

    public function test_pengaju_bisa_membatalkan_pengajuan_yang_masih_pending(): void
    {
        $id = $this->ajukan('andi@hris.test')->assertCreated()->json('data.id');

        $this->aksi('rina@hris.test', $id, 'cancel')->assertForbidden();

        $this->aksi('andi@hris.test', $id, 'cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_membatalkan_cuti_yang_sudah_disetujui_mengembalikan_saldo_dan_absensi(): void
    {
        $id = $this->cutiAndiDisetujui();

        $this->assertSame(3, $this->saldoTerpakai('EMP004', 'Cuti Tahunan'));

        $this->aksi('andi@hris.test', $id, 'cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, $this->saldoTerpakai('EMP004', 'Cuti Tahunan'));
        $this->assertSame(0, Attendance::where('status', 'leave')->count());
    }

    public function test_cuti_yang_sudah_berjalan_tidak_bisa_dibatalkan(): void
    {
        $id = $this->cutiAndiDisetujui();

        $this->at('2026-11-10 10:00:00'); // cuti sudah mulai sejak 9 Nov

        $this->aksi('andi@hris.test', $id, 'cancel')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(3, $this->saldoTerpakai('EMP004', 'Cuti Tahunan'));
    }

    // ---------- Hak akses & daftar ----------

    public function test_hak_lihat_detail_pengajuan(): void
    {
        $id = $this->ajukan('andi@hris.test')->assertCreated()->json('data.id');

        $this->loginAs('andi@hris.test');
        $this->getJson("/api/leave-requests/{$id}")->assertOk();

        $this->loginAs('manager@hris.test');
        $this->getJson("/api/leave-requests/{$id}")->assertOk()->assertJsonPath('data.can_act', true);

        $this->loginAs('hr@hris.test');
        $this->getJson("/api/leave-requests/{$id}")->assertOk()->assertJsonPath('data.can_act', false);

        $this->loginAs('rina@hris.test');
        $this->getJson("/api/leave-requests/{$id}")->assertForbidden();
    }

    public function test_riwayat_pengajuan_sendiri_hanya_berisi_milik_sendiri(): void
    {
        $this->ajukan('andi@hris.test')->assertCreated();
        $this->ajukan('rina@hris.test')->assertCreated();

        $this->loginAs('andi@hris.test');

        $this->getJson('/api/leave-requests/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.nip', 'EMP004');
    }

    public function test_daftar_pengajuan_untuk_hr_dan_manager(): void
    {
        $this->ajukan('andi@hris.test')->assertCreated();
        $this->ajukan('hr@hris.test')->assertCreated(); // bukan bawahan Budi

        $this->loginAs('hr@hris.test');
        $this->getJson('/api/leave-requests')->assertOk()->assertJsonCount(2, 'data');

        $this->loginAs('manager@hris.test');
        $this->getJson('/api/leave-requests')->assertOk()->assertJsonCount(1, 'data');

        $this->loginAs('andi@hris.test');
        $this->getJson('/api/leave-requests')->assertForbidden();
    }

    public function test_filter_awaiting_me_hanya_menampilkan_yang_menunggu_keputusanku(): void
    {
        $id = $this->ajukan('andi@hris.test')->assertCreated()->json('data.id');

        // Tahap atasan: hanya Budi yang punya tugas
        $this->loginAs('manager@hris.test');
        $this->getJson('/api/leave-requests?awaiting_me=1')->assertOk()->assertJsonCount(1, 'data');

        $this->loginAs('hr@hris.test');
        $this->getJson('/api/leave-requests?awaiting_me=1')->assertOk()->assertJsonCount(0, 'data');

        $this->aksi('manager@hris.test', $id, 'approve')->assertOk();

        // Tahap HR: giliran HR
        $this->loginAs('manager@hris.test');
        $this->getJson('/api/leave-requests?awaiting_me=1')->assertOk()->assertJsonCount(0, 'data');

        $this->loginAs('hr@hris.test');
        $this->getJson('/api/leave-requests?awaiting_me=1')->assertOk()->assertJsonCount(1, 'data');
    }
}
