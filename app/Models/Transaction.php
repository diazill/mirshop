<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Penjualan. Dibuat dan dibatalkan hanya lewat App\Services\TransactionService;
 * tidak pernah dihapus.
 */
class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_no', 'occurred_at', 'channel', 'source', 'status', 'user_id', 'device_id', 'synced_by',
        'customer_id', 'customer_name', 'customer_dropped', 'cashier_name', 'total_amount', 'price_mismatch', 'stock_conflict', 'below_cost', 'late_sync', 'needs_review',
        'payment_method', 'paid_amount', 'change_amount', 'notes', 'idempotency_key', 'request_hash',
    ];

    protected $casts = [
        'status' => TransactionStatus::class,
        'payment_method' => PaymentMethod::class,
        'total_amount' => 'integer',
        'paid_amount' => 'integer',
        'change_amount' => 'integer',
        'cancelled_at' => 'datetime',
        'occurred_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'price_mismatch' => 'boolean',
        'stock_conflict' => 'boolean',
        'below_cost' => 'boolean',
        'late_sync' => 'boolean',
        'customer_dropped' => 'boolean',
        'needs_review' => 'boolean',
    ];

    protected $hidden = ['request_hash'];

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Pelanggan (termasuk yang sudah dihapus, untuk riwayat). Null = pembeli umum. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** {id, name} untuk respons API; null = pembeli umum. */
    public function customerSummary(): ?array
    {
        return $this->customer_id ? ['id' => $this->customer_id, 'name' => $this->customer_name] : null;
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function inventoryMovements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'reference');
    }
}
