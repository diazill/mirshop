<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku', 'title', 'blurb', 'price', 'cost_price', 'weight_gram',
        'is_best_product', 'current_stock', 'min_stock', 'is_active',
        'category_id', 'brand_id'
    ];

    protected $casts = [
        'is_best_product' => 'boolean',
        'is_active' => 'boolean',
        'current_stock' => 'decimal:3',
        'min_stock' => 'decimal:3',
    ];

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function baseUnit(): HasOne
    {
        return $this->hasOne(ProductUnit::class)->where('is_base', true);
    }

    public function isLowStock(): bool
    {
        return $this->min_stock !== null
            && (float) $this->current_stock <= (float) $this->min_stock;
    }

    /** Format jumlah gaya Indonesia tanpa nol berlebih: 237.500 → "237,5", 1500 → "1.500". */
    public static function formatQty(string|int|float|null $qty): string
    {
        $formatted = number_format((float) $qty, 3, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    /** Satuan dasar dikunci setelah produk punya mutasi stok. */
    public function hasStockHistory(): bool
    {
        return $this->exists && $this->inventoryMovements()->exists();
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
