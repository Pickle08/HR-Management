<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Semua respons /api berisi data pribadi atau payroll. Tidak satu pun boleh
 * disimpan oleh cache bersama (CDN, reverse proxy) maupun cache browser.
 */
class NoStoreApiResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
