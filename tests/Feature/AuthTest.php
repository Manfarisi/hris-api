<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_login_berhasil_mengembalikan_token_dan_data_user(): void
    {
        $this->postJson('/api/login', [
            'email' => 'hr@hris.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'employee']])
            ->assertJsonPath('user.role', 'hr');
    }

    public function test_login_gagal_kalau_password_salah(): void
    {
        $this->postJson('/api/login', [
            'email' => 'hr@hris.test',
            'password' => 'salah-total',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_gagal_kalau_karyawan_nonaktif(): void
    {
        Employee::where('nip', 'EMP004')->update(['status' => 'inactive']);

        $this->postJson('/api/login', [
            'email' => 'andi@hris.test',
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_endpoint_terproteksi_membalas_401_json_tanpa_token(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_me_mengembalikan_data_user_yang_login(): void
    {
        Sanctum::actingAs(User::where('email', 'andi@hris.test')->first());

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'andi@hris.test')
            ->assertJsonPath('user.employee.nip', 'EMP004')
            ->assertJsonPath('user.employee.department', 'IT');
    }

    public function test_token_tidak_berlaku_lagi_setelah_logout(): void
    {
        $token = $this->postJson('/api/login', [
            'email' => 'hr@hris.test',
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertOk();

        // Guard menyimpan user di memori selama satu proses tes, jadi harus direset
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
}