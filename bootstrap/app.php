<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureUserHasRole::class,
            'device' => \App\Http\Middleware\EnsureRegisteredDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Format error API seragam: { code, message, errors }.
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'code' => 'VALIDASI_GAGAL',
                    'message' => 'Data yang dikirim tidak valid.',
                    'errors' => $exception->errors(),
                ], $exception->status);
            }
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'code' => 'TIDAK_TERAUTENTIKASI',
                    'message' => 'Autentikasi diperlukan.',
                ], 401);
            }
        });

        // 404, 405, 429, dst. untuk API: format sama, tanpa detail internal (mis. nama route).
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            [$code, $message] = match ($status) {
                403 => ['AKSES_DITOLAK', 'Anda tidak memiliki izin untuk mengakses fitur ini.'],
                404 => ['TIDAK_DITEMUKAN', 'Data atau alamat yang diminta tidak ditemukan.'],
                405 => ['METODE_TIDAK_DIDUKUNG', 'Metode HTTP tidak didukung untuk alamat ini.'],
                413 => ['DATA_TERLALU_BESAR', 'Data yang dikirim terlalu besar.'],
                429 => ['TERLALU_BANYAK_PERMINTAAN', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.'],
                default => ['KESALAHAN_HTTP', 'Permintaan tidak dapat diproses.'],
            };

            return response()->json(['code' => $code, 'message' => $message], $status, $exception->getHeaders());
        });
    })->create();
