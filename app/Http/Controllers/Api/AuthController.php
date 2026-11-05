<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login
     *
     * Menukar email dan password dengan token Sanctum. Kirim token itu di header
     * `Authorization: Bearer <token>` untuk semua endpoint lain. Dibatasi 5 percobaan per menit.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        $user->load('employee');

        if ($user->employee && $user->employee->status === 'inactive') {
            return response()->json([
                'message' => 'Akun kamu sudah tidak aktif. Hubungi HR.',
            ], 403);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'web')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Logout
     *
     * Mencabut token yang sedang dipakai.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Profil saya
     *
     * Data akun yang sedang login beserta data karyawannya.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('employee.department', 'employee.position');
        $employee = $user->employee;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'employee' => $employee ? [
                'id' => $employee->id,
                'nip' => $employee->nip,
                'full_name' => $employee->full_name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'manager_id' => $employee->manager_id,
            ] : null,
        ];
    }
}