<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use App\Models\ProductUnit;
use App\Services\PricingService;
use App\Support\ProductUnitPayload;
use Illuminate\Http\JsonResponse;

/**
 * Scan barcode di aplikasi HP (kasir dan gudang): barcode menunjuk ke satu satuan produk,
 * mis. barcode karung = 1 Karung Pakan Ayam.
 */
#[Group('Kasir (POS)', 'Penjualan online dari aplikasi HP', weight: 2)]
class BarcodeController extends Controller
{
    /**
     * Scan barcode.
     *
     * Barcode menunjuk ke satu satuan produk (mis. barcode karung = 1 Karung).
     * Mengembalikan harga per satuan, tingkat harga, dan stok dalam satuan dasar.
     * Peran: owner, admin, kasir, gudang.
     */
    public function __invoke(string $barcode, PricingService $pricing): JsonResponse
    {
        $unit = ProductUnit::query()
            ->with(['product.baseUnit.unit', 'unit', 'prices'])
            ->where('barcode', $barcode)
            ->first();

        if (! $unit || ! $unit->product) {
            return response()->json([
                'code' => 'BARCODE_TIDAK_DITEMUKAN',
                'message' => "Barcode {$barcode} tidak terdaftar.",
            ], 404);
        }

        return response()->json(['data' => ProductUnitPayload::make($unit, $pricing)]);
    }
}
