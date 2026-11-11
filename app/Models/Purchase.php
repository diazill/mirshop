<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Pembelian dari pemasok. Alur: draft → received (stok masuk lewat PurchaseService) / cancelled.
 * Pembelian tidak dihapus permanen; item hanya bisa diubah selama draft.
 */
class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no', 'status', 'supplier_invoice_no', 'total_cost', 'purchase_date',
        'supplier_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'status' => PurchaseStatus::class,
        'purchase_date' => 'date',
        'total_cost' => 'integer',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
        'total_cost' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Purchase $purchase): void {
            $purchase->reference_no ??= static::nextReferenceNo();
            $purchase->created_by ??= Auth::id();
        });
    }

    /** Nomor pembelian berurutan per hari: PB-20261111-0001. */
    public static function nextReferenceNo(): string
    {
        $prefix = 'PB-' . now()->format('Ymd') . '-';
        $last = static::query()->where('reference_no', 'like', $prefix . '%')->max('reference_no');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseStatus::Draft;
    }

    public function recalculateTotal(): void
    {
        $this->forceFill(['total_cost' => (int) $this->purchaseItems()->sum('subtotal')])->saveQuietly();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function inventoryMovements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'reference');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
