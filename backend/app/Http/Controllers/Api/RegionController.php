<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RegionController extends Controller
{
    private const LEVELS = [
        'provinces' => ['table' => 'provinces', 'parent' => null, 'parent_table' => null],
        'regencies' => ['table' => 'regencies', 'parent' => 'province_code', 'parent_table' => 'provinces'],
        'districts' => ['table' => 'districts', 'parent' => 'regency_code', 'parent_table' => 'regencies'],
        'villages' => ['table' => 'villages', 'parent' => 'district_code', 'parent_table' => 'districts'],
    ];

    public function index(Request $request, string $level): JsonResponse
    {
        $config = self::LEVELS[$level];

        $query = DB::table($config['table'])->select('code', 'name')->orderBy('name');

        if ($config['parent'] !== null) {
            $data = $request->validate([
                $config['parent'] => ['required', 'string', 'max:13', Rule::exists($config['parent_table'], 'code')],
            ]);

            $query->where($config['parent'], $data[$config['parent']]);
        }

        // Data wilayah bukan data pribadi, tapi tetap di balik login dan hanya boleh di-cache per pengguna.
        return response()->json(['data' => $query->get()])
            ->header('Cache-Control', 'private, max-age=86400');
    }
}
