<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_dokumentasi_openapi_bisa_digenerate_dan_mencakup_semua_modul(): void
    {
        $path = storage_path('framework/testing/openapi.json');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $this->artisan('scramble:export', ['--path' => $path])->assertSuccessful();

        $spec = json_decode(file_get_contents($path), true);

        @unlink($path);

        $this->assertIsArray($spec);
        $this->assertArrayHasKey('paths', $spec);

        $paths = array_keys($spec['paths']);

        // Satu endpoint penting dari tiap modul
        foreach ([
            '/login',
            '/employees',
            '/attendance/clock-in',
            '/leave-requests/{leaveRequest}/approve',
            '/payrolls/generate',
            '/payrolls/{payroll}/pdf',
        ] as $suffix) {
            $this->assertTrue(
                collect($paths)->contains(fn ($p) => str_ends_with($p, $suffix)),
                "Endpoint {$suffix} tidak ada di dokumentasi."
            );
        }

        $this->assertGreaterThanOrEqual(25, count($paths));
        $this->assertNotEmpty($spec['components']['securitySchemes'] ?? []);
    }
}