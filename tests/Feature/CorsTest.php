<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Dua origin, sama seperti config asli. Dengan satu origin saja, library CORS
        // selalu mengirim header berisi origin itu (browser tetap menolak yang tidak cocok).
        config(['cors.allowed_origins' => [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ]]);
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ]);
    }

    public function test_frontend_yang_diizinkan_lolos_preflight(): void
    {
        $this->preflight('http://localhost:5173')
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');

        $this->preflight('http://127.0.0.1:5173')
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173');
    }

    public function test_origin_lain_tidak_diberi_izin(): void
    {
        $this->preflight('http://situs-lain.test')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}