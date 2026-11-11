<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Catatan mutasi stok. Hanya dibuat lewat App\Services\StockService dan tidak boleh
 * diubah atau dihapus; koreksi dilakukan dengan mutasi baru.
 *
 * qty_change, stock_before, stock_after: dalam satuan dasar produk.
 * qty, unit_name, conversion_qty: snapshot satuan yang dipakai saat input.
 */
class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'product_unit_id', 'movement_type', 'reference_type', 'reference_id',
        'unit_name', 'conversion_qty', 'qty', 'qty_change', 'stock_before', 'stock_after',
        'reason', 'notes', 'user_id',
    ];

    protected $casts = [
        'movement_type' => MovementType::class,
        'conversion_qty' => 'decimal:3',
        'qty' => 'decimal:3',
        'qty_change' => 'decimal:3',
        'stock_before' => 'decimal:3',
        'stock_after' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Mutasi stok tidak boleh diubah. Buat mutasi koreksi baru.'));
        static::deleting(fn () => throw new LogicException('Mutasi stok tidak boleh dihapus. Buat mutasi koreksi baru.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Dokumen sumber: Purchase, Transaction, dst. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
