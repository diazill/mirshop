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
        'cashier_name', 'total_amount', 'price_mismatch', 'stock_conflict', 'below_cost', 'late_sync', 'needs_review',
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
