<?php

namespace App\Services;

use App\Models\ProductPrice;
use App\Models\ProductUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Harga jual per satuan. Urutan pencarian:
 * 1. harga khusus satuan itu untuk jenis harga yang diminta, tingkat min_qty tertinggi yang terpenuhi;
 * 2. harga eceran khusus satuan itu (bila jenis yang diminta belum diatur);
 * 3. harga dasar produk × isi satuan.
 */
class PricingService
{
    public function unitPrice(ProductUnit $unit, string|int|float $qty, string $priceType = 'eceran'): int
    {
        $qty = (string) $qty;

        $prices = $unit->relationLoaded('prices') ? $unit->prices : $unit->prices()->get();

        foreach (array_unique([$priceType, 'eceran']) as $type) {
            $match = $prices
                ->filter(fn (ProductPrice $price): bool => $price->price_type === $type
                    && BigDecimal::of((string) $price->min_qty)->isLessThanOrEqualTo($qty))
                ->sortByDesc(fn (ProductPrice $price): float => (float) $price->min_qty)
                ->first();

            if ($match) {
                return $match->price;
            }
        }

        return $this->basePrice($unit);
    }

    /** Harga dasar produk × isi satuan, dibulatkan ke rupiah. */
    public function basePrice(ProductUnit $unit): int
    {
        return BigDecimal::of((string) ((int) $unit->product->price))
            ->multipliedBy((string) $unit->conversion_qty)
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();
    }

    /** HPP per satuan = HPP per satuan dasar × isi satuan. */
    public function unitCost(ProductUnit $unit): int
    {
        return BigDecimal::of((string) ((int) $unit->product->cost_price))
            ->multipliedBy((string) $unit->conversion_qty)
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();
    }
}
