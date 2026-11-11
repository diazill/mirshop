<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Exceptions\PurchaseException;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Alur pembelian: terima barang (stok masuk + HPP rata-rata) dan pembatalan.
 * Semua langkah dalam satu transaksi DB: bila satu item gagal, tidak ada stok yang berubah.
 */
class PurchaseService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * Terima barang: stok semua item masuk, HPP produk diperbarui, status menjadi "received".
     */
    public function receive(Purchase $purchase, ?User $user = null): Purchase
    {
        $user ??= Auth::user();

        return DB::transaction(function () use ($purchase, $user) {
            $locked = $this->lock($purchase);

            if (! $locked->isDraft()) {
                throw new PurchaseException("Pembelian {$locked->reference_no} sudah {$locked->status->label()}.");
            }

            // Urut product_id agar urutan row lock konsisten (hindari deadlock).
            $items = $locked->purchaseItems()->with(['productUnit.unit', 'product'])->orderBy('product_id')->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw new PurchaseException('Pembelian belum punya item.');
            }

            foreach ($items as $item) {
                $this->receiveItem($locked, $item, $user);
            }

            $locked->forceFill([
                'status' => PurchaseStatus::Received,
                'total_cost' => (int) $items->sum('subtotal'),
                'received_at' => now(),
                'received_by' => $user?->getKey(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Batalkan pembelian. Draft: cukup ubah status. Sudah diterima: hanya owner, dan stok
     * ditarik kembali (gagal bila barang sudah terjual sehingga stok tidak cukup).
     * Catatan: HPP produk tidak dikembalikan otomatis.
     */
    public function cancel(Purchase $purchase, string $reason, ?User $user = null): Purchase
    {
        $user ??= Auth::user();

        if (blank($reason)) {
            throw new PurchaseException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($purchase, $reason, $user) {
            $locked = $this->lock($purchase);

            if ($locked->status === PurchaseStatus::Cancelled) {
                throw new PurchaseException("Pembelian {$locked->reference_no} sudah dibatalkan.");
            }

            if ($locked->status === PurchaseStatus::Received) {
                if (! $user?->hasRole('owner')) {
                    throw new PurchaseException('Hanya owner yang bisa membatalkan pembelian yang sudah diterima.');
                }

                $items = $locked->purchaseItems()->with(['productUnit.unit', 'product'])->orderBy('product_id')->orderBy('id')->get();

                foreach ($items as $item) {
                    $this->stock->decrease(
                        product: $item->product,
                        qty: $item->qty,
                        type: MovementType::PurchaseCancel,
                        unit: $this->unitFor($item),
                        reference: $locked,
                        notes: $reason,
                        user: $user,
                    );
                }
            }

            $locked->forceFill([
                'status' => PurchaseStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user?->getKey(),
                'cancel_reason' => $reason,
            ])->save();

            return $locked;
        });
    }

    private function receiveItem(Purchase $purchase, PurchaseItem $item, ?User $user): void
    {
        if ($item->product?->trashed()) {
            throw new PurchaseException("Produk {$item->product->title} sudah dihapus.");
        }

        $movement = $this->stock->increase(
            product: $item->product,
            qty: $item->qty,
            type: MovementType::Purchase,
            unit: $this->unitFor($item),
            reference: $purchase,
            user: $user,
        );

        // Produk sudah ter-lock oleh StockService sampai transaksi selesai.
        $this->updateAverageCost($item->product, (string) $movement->stock_before, (string) $item->base_qty, (int) $item->subtotal);
    }

    /**
     * HPP rata-rata tertimbang per satuan dasar:
     * (stok lama × HPP lama + nilai pembelian) / (stok lama + jumlah masuk).
     * Bila stok lama ≤ 0, HPP = harga beli per satuan dasar.
     */
    private function updateAverageCost(Product $product, string $stockBefore, string $baseQty, int $subtotal): void
    {
        $before = BigDecimal::of($stockBefore);
        $incoming = BigDecimal::of($baseQty);
        // Baca ulang: produk yang sama bisa muncul di beberapa item (mis. Karung dan Kg).
        $oldCost = BigDecimal::of((string) ((int) Product::withTrashed()->whereKey($product->getKey())->value('cost_price')));

        if ($incoming->isZero()) {
            return;
        }

        $newCost = $before->isPositive()
            ? $before->multipliedBy($oldCost)->plus($subtotal)->dividedBy($before->plus($incoming), 0, RoundingMode::HALF_UP)
            : BigDecimal::of($subtotal)->dividedBy($incoming, 0, RoundingMode::HALF_UP);

        Product::withTrashed()->whereKey($product->getKey())->update(['cost_price' => $newCost->toInt()]);
    }

    private function unitFor(PurchaseItem $item): ProductUnit
    {
        if (! $item->productUnit) {
            throw new PurchaseException("Satuan untuk {$item->product?->title} sudah dihapus dari produk. Perbarui item pembelian.");
        }

        return $item->productUnit;
    }

    private function lock(Purchase $purchase): Purchase
    {
        return Purchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();
    }
}
