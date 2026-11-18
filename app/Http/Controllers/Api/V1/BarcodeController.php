<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Services\PricingService;
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

        $product = $unit->product;

        return response()->json(['data' => [
            'product_unit_id' => $unit->id,
            'product_id' => $product->id,
            'title' => $product->title,
            'is_active' => $product->is_active,
            'unit_name' => $unit->unit->name,
            'conversion_qty' => $unit->conversion_qty,
            'base_unit_name' => $product->baseUnit?->unit?->name,
            'allow_decimal_qty' => $unit->allow_decimal_qty,
            'is_sellable' => $unit->is_sellable,
            'is_purchasable' => $unit->is_purchasable,
            'price' => $pricing->unitPrice($unit, 1),
            'price_tiers' => $unit->prices
                ->sortBy([['price_type', 'asc'], ['min_qty', 'asc']])
                ->map(fn (ProductPrice $price): array => [
                    'price_type' => $price->price_type,
                    'min_qty' => $price->min_qty,
                    'price' => $price->price,
                ])
                ->values(),
            'stock_base_qty' => $product->current_stock,
        ]]);
    }
}
