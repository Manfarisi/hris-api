<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeSelfProtectionTest extends TestCase
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

    public function test_hr_tidak_bisa_menonaktifkan_diri_sendiri_lewat_update(): void
    {
        $this->loginAs('hr@hris.test');
        $id = $this->employeeId('EMP002');

        $this->putJson("/api/employees/{$id}", ['status' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'active']);
    }

    public function test_super_admin_juga_tidak_bisa_menonaktifkan_diri_sendiri(): void
    {
        $this->loginAs('admin@hris.test');
        $id = $this->employeeId('EMP001');

        $this->putJson("/api/employees/{$id}", ['status' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'active']);
    }

    public function test_hr_tidak_bisa_mengubah_role_akunnya_sendiri(): void
    {
        $this->loginAs('hr@hris.test');
        $id = $this->employeeId('EMP002');

        $this->putJson("/api/employees/{$id}", ['role' => 'employee'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('users', ['email' => 'hr@hris.test', 'role' => 'hr']);
    }

    public function test_mengedit_data_sendiri_dengan_role_yang_sama_tetap_boleh(): void
    {
        $this->loginAs('hr@hris.test');
        $id = $this->employeeId('EMP002');

        // Persis seperti form edit di frontend: role dikirim apa adanya
        $this->putJson("/api/employees/{$id}", ['role' => 'hr', 'phone' => '081200000000'])
            ->assertOk();

        $this->assertDatabaseHas('employees', ['id' => $id, 'phone' => '081200000000']);
        $this->assertDatabaseHas('users', ['email' => 'hr@hris.test', 'role' => 'hr']);
    }

    public function test_hr_tetap_bisa_menonaktifkan_dan_mengaktifkan_kembali_karyawan_lain(): void
    {
        $this->loginAs('hr@hris.test');
        $id = $this->employeeId('EMP004');

        $this->putJson("/api/employees/{$id}", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'inactive']);

        $this->putJson("/api/employees/{$id}", ['status' => 'active'])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'active']);
    }

    public function test_hr_bisa_mengubah_role_karyawan_lain(): void
    {
        $this->loginAs('hr@hris.test');
        $id = $this->employeeId('EMP004');

        $this->putJson("/api/employees/{$id}", ['role' => 'manager'])->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'andi@hris.test', 'role' => 'manager']);
    }
}