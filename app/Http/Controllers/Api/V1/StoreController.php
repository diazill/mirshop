<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\StoreProfile;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Kasir (POS)', 'Penjualan online dari aplikasi HP', weight: 2)]
class StoreController extends Controller
{
    /**
     * Info toko (kepala struk).
     *
     * Diatur owner di panel admin (Pengaturan → Pengaturan Toko). `logo_url` null bila belum diunggah.
     * `timezone` = zona waktu toko, dipakai untuk "hari ini" di ringkasan. Juga ikut di `store` pada
     * GET /sync/catalog untuk dipakai offline.
     * Peran: owner, admin, kasir, gudang.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => StoreProfile::payload()]);
    }
}
