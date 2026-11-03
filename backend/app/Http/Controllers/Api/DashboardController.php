<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        // year/month hanya dipakai blok payroll HR dan Finance; role lain mengabaikannya.
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        return response()->json([
            'data' => $dashboard->forUser(
                $request->user(),
                (int) ($filters['year'] ?? now()->year),
                (int) ($filters['month'] ?? now()->month),
            ),
        ]);
    }
}
