<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Baris pembelian. qty & buy_price dalam satuan yang dipilih (mis. 10 Karung @ Rp420.000);
 * base_qty = qty × conversion_qty dalam satuan dasar. Snapshot satuan diisi otomatis.
 */
class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = ['purchase_id', 'product_id', 'product_unit_id', 'qty', 'buy_price'];

    protected $casts = [
        'qty' => 'decimal:3',
        'base_qty' => 'decimal:3',
        'conversion_qty' => 'decimal:3',
        'buy_price' => 'integer',
        'subtotal' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (PurchaseItem $item): void {
            $item->assertPurchaseIsDraft();

            $unit = ProductUnit::query()->with('unit')->find($item->product_unit_id);
            if (! $unit) {
                throw new LogicException('Satuan pembelian tidak ditemukan.');
            }

            $qty = BigDecimal::of((string) $item->qty);

            $item->product_id = $unit->product_id;
            $item->unit_name = $unit->unit?->name;
            $item->conversion_qty = (string) $unit->conversion_qty;
            $item->base_qty = (string) $qty->multipliedBy((string) $unit->conversion_qty)->toScale(3, RoundingMode::HALF_UP);
            $item->subtotal = $qty->multipliedBy((int) $item->buy_price)->toScale(0, RoundingMode::HALF_UP)->toInt();
        });

        static::deleting(fn (PurchaseItem $item) => $item->assertPurchaseIsDraft());
    }

    private function assertPurchaseIsDraft(): void
    {
        $purchase = $this->purchase()->first();

        if ($purchase && ! $purchase->isDraft()) {
            throw new LogicException('Item pembelian yang sudah diterima/dibatalkan tidak bisa diubah.');
        }
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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
