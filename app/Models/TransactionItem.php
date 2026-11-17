<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Baris penjualan. qty, price_at_time dan cost_price_at_time per satuan yang dijual;
 * base_qty dalam satuan dasar. Semua nilai adalah snapshot saat transaksi dan tidak berubah.
 */
class TransactionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id', 'product_id', 'product_unit_id', 'unit_name', 'conversion_qty',
        'qty', 'base_qty', 'price_type', 'price_at_time', 'official_price', 'cost_price_at_time', 'subtotal',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'base_qty' => 'decimal:3',
        'conversion_qty' => 'decimal:3',
        'price_at_time' => 'integer',
        'official_price' => 'integer',
        'cost_price_at_time' => 'integer',
        'subtotal' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Item penjualan tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Item penjualan tidak boleh dihapus.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
