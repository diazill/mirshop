<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            return response()->json([
                'code' => 'AKUN_NONAKTIF',
                'message' => 'Akun tidak aktif.',
            ], 403);
        }

        // HP hilang: perangkat dinonaktifkan owner, semua token dari HP itu berhenti berlaku.
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->device_id && ! $token->device?->is_active) {
            return response()->json([
                'code' => 'PERANGKAT_NONAKTIF',
                'message' => 'Perangkat ini dinonaktifkan. Hubungi owner.',
            ], 403);
        }

        return $next($request);
    }
}
