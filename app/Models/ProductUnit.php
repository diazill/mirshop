<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satuan yang dipakai sebuah produk. Stok selalu dicatat dalam satuan dasar (is_base),
 * satuan lain dikonversi: base_qty = qty × conversion_qty.
 */
class ProductUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'unit_id', 'conversion_qty', 'is_base', 'barcode',
        'allow_decimal_qty', 'is_sellable', 'is_purchasable',
    ];

    protected $casts = [
        'conversion_qty' => 'decimal:3',
        'is_base' => 'boolean',
        'allow_decimal_qty' => 'boolean',
        'is_sellable' => 'boolean',
        'is_purchasable' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductUnit $productUnit): void {
            if ($productUnit->is_base) {
                $productUnit->conversion_qty = 1;
            }

            if ($productUnit->barcode === '') {
                $productUnit->barcode = null;
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /** Konversi jumlah dalam satuan ini ke satuan dasar produk. */
    public function toBaseQty(string|int|float $qty): string
    {
        return (string) BigDecimal::of((string) $qty)
            ->multipliedBy((string) $this->conversion_qty)
            ->toScale(3, RoundingMode::HALF_UP);
    }
}
