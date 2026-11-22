<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BarcodeController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PosTransactionController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductImageController;
use App\Http\Controllers\Api\V1\ProductUnitBarcodeController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\WarehouseStockController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Middleware\PublicCacheHeaders;

Route::prefix('v1')->group(function () {
    // Katalog publik untuk toko online: tanpa login, di-cache 60 detik.
    Route::middleware(['throttle:public-api', PublicCacheHeaders::class])->group(function () {
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{key}', [ProductController::class, 'show']);
        Route::get('/categories', [CatalogController::class, 'categories']);
        Route::get('/brands', [CatalogController::class, 'brands']);
        Route::get('/store/public', [CatalogController::class, 'store']);
    });

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/barcode/{barcode}', BarcodeController::class)
            ->middleware('role:owner,admin,kasir,gudang');

        Route::get('/store', [StoreController::class, 'show'])
            ->middleware('role:owner,admin,kasir,gudang');

        // Pelanggan (Mode Kasir).
        Route::middleware('role:owner,admin,kasir')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('throttle:30,1');
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        });

        // Mode Kasir aplikasi HP.
        Route::prefix('pos')->middleware('role:owner,admin,kasir')->group(function () {
            Route::get('/summary', [PosTransactionController::class, 'summary']);
            Route::get('/transactions', [PosTransactionController::class, 'index']);
            Route::post('/transactions', [PosTransactionController::class, 'store'])
                ->middleware('throttle:60,1');
            Route::get('/transactions/{transaction}', [PosTransactionController::class, 'show']);
            Route::post('/transactions/{transaction}/cancel', [PosTransactionController::class, 'cancel'])
                ->middleware('role:owner');
        });

        // Kelola barang (aplikasi HP, online): barcode, stok, foto.
        Route::middleware('role:owner,admin,gudang')->group(function () {
            Route::put('/product-units/{productUnit}/barcode', [ProductUnitBarcodeController::class, 'update']);
            Route::post('/stock/opname', [WarehouseStockController::class, 'opname'])->middleware('throttle:60,1');
            Route::post('/stock/adjust', [WarehouseStockController::class, 'adjust'])->middleware('throttle:60,1');
            Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->middleware('throttle:20,1');
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
