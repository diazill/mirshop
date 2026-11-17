<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BarcodeController;
use App\Http\Controllers\Api\V1\PosTransactionController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SyncController;

Route::prefix('v1')->group(function () {
    Route::get('/products', [ProductController::class, 'index'])
        ->middleware('throttle:120,1');

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/barcode/{barcode}', BarcodeController::class)
            ->middleware('role:owner,admin,kasir,gudang');

        // Mode Kasir aplikasi HP.
        Route::prefix('pos')->middleware('role:owner,admin,kasir')->group(function () {
            Route::get('/transactions', [PosTransactionController::class, 'index']);
            Route::post('/transactions', [PosTransactionController::class, 'store'])
                ->middleware('throttle:60,1');
            Route::get('/transactions/{transaction}', [PosTransactionController::class, 'show']);
            Route::post('/transactions/{transaction}/cancel', [PosTransactionController::class, 'cancel'])
                ->middleware('role:owner');
        });

        // Aplikasi HP offline-first: wajib header X-Device-Code dari perangkat terdaftar.
        Route::prefix('sync')->middleware('device')->group(function () {
            Route::get('/catalog', [SyncController::class, 'catalog'])
                ->middleware('role:owner,admin,kasir,gudang');
            Route::post('/transactions', [SyncController::class, 'transactions'])
                ->middleware(['role:owner,admin,kasir', 'throttle:30,1']);
        });
    });
});
