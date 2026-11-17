<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint sinkron hanya untuk token yang login dari perangkat terdaftar dan aktif.
 * Perangkat dibaca dari token (diikat saat login), bukan dari header: kode perangkat
 * tercetak di struk sehingga bukan rahasia. Header X-Device-Code, bila dikirim, harus cocok.
 */
class EnsureRegisteredDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $device = $token instanceof PersonalAccessToken ? $token->device : null;

        if (! $device) {
            return response()->json([
                'code' => 'PERANGKAT_TIDAK_DIKENAL',
                'message' => 'Login ulang dari aplikasi HP dengan kode perangkat terdaftar untuk memakai sinkron.',
            ], 403);
        }

        if (! $device->is_active) {
            return response()->json([
                'code' => 'PERANGKAT_NONAKTIF',
                'message' => "Perangkat {$device->code} dinonaktifkan.",
            ], 403);
        }

        $header = $request->header('X-Device-Code');

        if (filled($header) && strtoupper($header) !== $device->code) {
            return response()->json([
                'code' => 'PERANGKAT_TIDAK_COCOK',
                'message' => "Token ini milik perangkat {$device->code}.",
            ], 403);
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
