<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/user', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
    }

    public function test_only_configured_origins_get_credentialed_cors_headers(): void
    {
        // Berkas config harus aman: tidak ada wildcard, dan cookie lintas asal diizinkan.
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotEmpty(config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_origins'));

        // Dua asal atau lebih: asal permintaan dipantulkan hanya kalau terdaftar.
        config(['cors.allowed_origins' => ['http://localhost:5173', 'http://localhost:3000']]);

        $allowed = $this->preflight('http://localhost:5173');
        $this->assertSame('http://localhost:5173', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $allowed->headers->get('Access-Control-Allow-Credentials'));

        $denied = $this->preflight('http://evil.test');
        $this->assertNull($denied->headers->get('Access-Control-Allow-Origin'));

        // Satu asal: header selalu berisi asal itu. Yang penting, bukan asal penyerang,
        // jadi browser tetap menolaknya.
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $single = $this->preflight('http://evil.test');
        $this->assertNotSame('http://evil.test', $single->headers->get('Access-Control-Allow-Origin'));
    }
}
