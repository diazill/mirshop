<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\RecordsSyncDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * current_stock sengaja tidak fillable: stok hanya berubah lewat App\Services\StockService.
 */
class Product extends Model
{
    use Auditable, HasFactory, HasSlug, RecordsSyncDeletion, SoftDeletes;

    /** Diawasi audit log. Stok tidak (sudah tercatat di riwayat mutasi). */
    public function auditedFields(): array
    {
        return ['title', 'slug', 'price', 'cost_price', 'is_active', 'category_id', 'brand_id', 'min_stock', 'is_best_product'];
    }

    public function auditLabel(): string
    {
        return (string) $this->title;
    }

    protected static function booted(): void
    {
        // Slug diganti: simpan slug lama agar tautan lama tetap membuka produk ini.
        static::updated(function (Product $product): void {
            if (! $product->wasChanged('slug')) {
                return; // update stok/harga: tanpa query tambahan
            }

            $old = $product->getOriginal('slug');

            if (filled($old)) {
                ProductSlug::query()->firstOrCreate(['slug' => $old], ['product_id' => $product->id]);
            }

            // Kembali memakai slug lama sendiri: hapus dari riwayat.
            ProductSlug::query()->where('product_id', $product->id)->where('slug', $product->slug)->delete();
        });
    }

    /** Cari produk dari id (tautan lama /produk/8), slug, atau slug lama. Termasuk yang nonaktif. */
    public static function findForStorefront(string $key): ?self
    {
        if (ctype_digit($key)) {
            return static::query()->find((int) $key);
        }

        return static::query()->where('slug', $key)->first()
            ?? ProductSlug::query()->where('slug', $key)->first()?->product;
    }

    protected function slugSource(): string
    {
        return 'title';
    }

    protected function slugFallback(): string
    {
        return 'produk';
    }

    protected function slugReservedElsewhere(string $slug): bool
    {
        return ProductSlug::query()
            ->where('slug', $slug)
            ->when($this->exists, fn ($q) => $q->where('product_id', '!=', $this->id))
            ->exists();
    }

    protected $fillable = [
        'sku', 'title', 'slug', 'blurb', 'price', 'cost_price', 'weight_gram',
        'is_best_product', 'min_stock', 'is_active',
        'category_id', 'brand_id'
    ];

    protected $casts = [
        'is_best_product' => 'boolean',
        'is_active' => 'boolean',
        'current_stock' => 'decimal:3',
        'min_stock' => 'decimal:3',
    ];

    public function slugs(): HasMany
    {
        return $this->hasMany(ProductSlug::class);
    }

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
