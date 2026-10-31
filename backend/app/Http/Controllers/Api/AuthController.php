<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // is_active ikut di kondisi login: akun nonaktif diperlakukan sama
        // seperti salah password, supaya tidak membocorkan status akun.
        if (!Auth::attempt([...$credentials, 'is_active' => true])) {
            Audit::record('auth.login_failed', newValues: ['email' => $credentials['email']]);
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        $request->session()->regenerate();

        Audit::record('auth.login', Auth::user());

        return response()->json(Auth::user());
    }

    public function logout(Request $request): Response
    {
        Audit::record('auth.logout', $request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
