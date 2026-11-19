<?php

namespace App\Support;

use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Services\PricingService;

/**
 * Data satu satuan produk untuk aplikasi HP. Dipakai bersama oleh scan barcode
 * (GET /barcode/{barcode}) dan pasang barcode (PUT /product-units/{id}/barcode).
 */
final class ProductUnitPayload
{
    public static function make(ProductUnit $unit, PricingService $pricing): array
    {
        $unit->loadMissing(['product.baseUnit.unit', 'unit', 'prices']);
        $product = $unit->product;

        return [
            'product_unit_id' => $unit->id,
            'product_id' => $product->id,
            'title' => $product->title,
            'is_active' => $product->is_active,
            'barcode' => $unit->barcode,
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
        ];
    }
}
