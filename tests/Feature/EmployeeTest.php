<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());
    }

    private function employeeId(string $nip): int
    {
        return Employee::where('nip', $nip)->value('id');
    }

    // ---------- Departemen & jabatan ----------

    public function test_semua_user_login_boleh_lihat_daftar_departemen(): void
    {
        $this->loginAs('andi@hris.test');

        $this->getJson('/api/departments')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'employees_count']]]);
    }

    public function test_hr_bisa_menambah_departemen_dan_nama_kembar_ditolak(): void
    {
        $this->loginAs('hr@hris.test');

        $this->postJson('/api/departments', ['name' => 'Legal'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Legal');

        $this->postJson('/api/departments', ['name' => 'Legal'])
            ->assertUnprocessable();
    }

    public function test_karyawan_biasa_tidak_boleh_menambah_departemen(): void
    {
        $this->loginAs('andi@hris.test');

        $this->postJson('/api/departments', ['name' => 'Legal'])->assertForbidden();
    }

    public function test_departemen_yang_masih_punya_karyawan_tidak_bisa_dihapus(): void
    {
        $this->loginAs('hr@hris.test');

        $itId = Department::where('name', 'IT')->value('id');

        $this->deleteJson("/api/departments/{$itId}")->assertUnprocessable();
        $this->assertDatabaseHas('departments', ['id' => $itId]);
    }

    public function test_jabatan_yang_masih_dipakai_tidak_bisa_dihapus(): void
    {
        $this->loginAs('hr@hris.test');

        $id = Position::where('name', 'Software Engineer')->value('id');

        $this->deleteJson("/api/positions/{$id}")->assertUnprocessable();
    }

    // ---------- Karyawan: hak akses ----------

    public function test_hr_melihat_semua_karyawan_lengkap_dengan_gaji(): void
    {
        $this->loginAs('hr@hris.test');

        $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure(['data' => [['id', 'nip', 'full_name', 'base_salary']]]);
    }

    public function test_manager_hanya_melihat_bawahan_dan_tanpa_gaji(): void
    {
        $this->loginAs('manager@hris.test');

        $response = $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            ['EMP004', 'EMP005'],
            collect($response->json('data'))->pluck('nip')->all()
        );

        $response->assertJsonMissingPath('data.0.base_salary');
    }

    public function test_karyawan_biasa_tidak_boleh_melihat_daftar_karyawan(): void
    {
        $this->loginAs('andi@hris.test');

        $this->getJson('/api/employees')->assertForbidden();
    }

    public function test_karyawan_hanya_boleh_melihat_data_dirinya_sendiri(): void
    {
        $this->loginAs('andi@hris.test');

        $this->getJson('/api/employees/'.$this->employeeId('EMP004'))
            ->assertOk()
            ->assertJsonPath('data.nip', 'EMP004')
            ->assertJsonStructure(['data' => ['base_salary']]);

        $this->getJson('/api/employees/'.$this->employeeId('EMP005'))->assertForbidden();
    }

    public function test_manager_tidak_boleh_melihat_karyawan_di_luar_timnya(): void
    {
        $this->loginAs('manager@hris.test');

        $this->getJson('/api/employees/'.$this->employeeId('EMP002'))->assertForbidden();
    }

    public function test_pencarian_dan_filter_karyawan_bekerja(): void
    {
        $this->loginAs('hr@hris.test');

        $this->getJson('/api/employees?search=andi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nip', 'EMP004');

        $itId = Department::where('name', 'IT')->value('id');

        $this->getJson("/api/employees?department_id={$itId}")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    // ---------- Karyawan: tambah, ubah, nonaktifkan ----------

    private function payloadKaryawanBaru(array $override = []): array
    {
        return array_merge([
            'full_name' => 'Dewi Lestari',
            'nip' => 'EMP006',
            'department_id' => Department::where('name', 'IT')->value('id'),
            'position_id' => Position::where('name', 'Software Engineer')->value('id'),
            'manager_id' => $this->employeeId('EMP003'),
            'join_date' => '2026-11-01',
            'base_salary' => 9000000,
            'phone' => '081234567890',
            'email' => 'dewi@hris.test',
            'password' => 'password123',
            'role' => 'employee',
        ], $override);
    }

    public function test_hr_menambah_karyawan_dengan_akun_dan_saldo_cuti_otomatis(): void
    {
        $this->loginAs('hr@hris.test');

        $this->postJson('/api/employees', $this->payloadKaryawanBaru())
            ->assertCreated()
            ->assertJsonPath('data.nip', 'EMP006')
            ->assertJsonPath('data.account.email', 'dewi@hris.test');

        $dewiId = $this->employeeId('EMP006');

        $this->assertSame(3, LeaveBalance::where('employee_id', $dewiId)->count());

        // Akun barunya langsung bisa dipakai login
        $this->postJson('/api/login', [
            'email' => 'dewi@hris.test',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_tambah_karyawan_ditolak_kalau_data_tidak_valid(): void
    {
        $this->loginAs('hr@hris.test');

        // NIP kembar
        $this->postJson('/api/employees', $this->payloadKaryawanBaru(['nip' => 'EMP001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nip');

        // Email diisi tapi password & role tidak
        $payload = $this->payloadKaryawanBaru();
        unset($payload['password'], $payload['role']);

        $this->postJson('/api/employees', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'role']);
    }

    public function test_hanya_hr_yang_boleh_menambah_karyawan(): void
    {
        $this->loginAs('manager@hris.test');

        $this->postJson('/api/employees', $this->payloadKaryawanBaru())->assertForbidden();
    }

    public function test_hr_bisa_mengubah_sebagian_field_saja(): void
    {
        $this->loginAs('hr@hris.test');

        $id = $this->employeeId('EMP004');

        $this->putJson("/api/employees/{$id}", ['base_salary' => 12000000])
            ->assertOk()
            ->assertJsonPath('data.base_salary', 12000000)
            ->assertJsonPath('data.full_name', 'Andi Pratama');
    }

    public function test_karyawan_tidak_boleh_menjadi_atasan_dirinya_sendiri(): void
    {
        $this->loginAs('hr@hris.test');

        $id = $this->employeeId('EMP004');

        $this->putJson("/api/employees/{$id}", ['manager_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');
    }

    public function test_menonaktifkan_karyawan_mencabut_akses_login(): void
    {
        $this->loginAs('hr@hris.test');

        $id = $this->employeeId('EMP004');

        $this->deleteJson("/api/employees/{$id}")->assertOk();

        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'inactive']);

        $this->postJson('/api/login', [
            'email' => 'andi@hris.test',
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_hr_tidak_bisa_menonaktifkan_dirinya_sendiri(): void
    {
        $this->loginAs('hr@hris.test');

        $this->deleteJson('/api/employees/'.$this->employeeId('EMP002'))
            ->assertUnprocessable();

        $this->assertDatabaseHas('employees', ['nip' => 'EMP002', 'status' => 'active']);
    }
}