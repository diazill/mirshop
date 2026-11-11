<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStockQuantityException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu perubahan stok.
 *
 * - Setiap perubahan berjalan dalam transaksi DB dengan row lock pada produk, sehingga
 *   dua kasir yang menjual barang yang sama bersamaan tidak membuat stok salah.
 * - Stok disimpan dalam satuan dasar; jumlah input dikonversi lewat ProductUnit.
 * - Setiap perubahan menghasilkan InventoryMovement yang tidak bisa diubah/dihapus.
 *
 * Bila beberapa produk diubah dalam satu transaksi (mis. satu struk kasir), panggil
 * dengan urutan product_id menaik untuk menghindari deadlock.
 */
class StockService
{
    private const SCALE = 3;

    /** Stok masuk: pembelian, batal jual, retur jual. */
    public function increase(
        Product $product,
        string|int|float $qty,
        MovementType $type,
        ?ProductUnit $unit = null,
        ?Model $reference = null,
        ?string $notes = null,
        ?User $user = null,
    ): InventoryMovement {
        $qty = $this->positiveQty($qty, $product, $unit);

        return $this->apply($product, $unit, $qty, $qty, $type, $reference, null, $notes, $user);
    }

    /**
     * Stok keluar: penjualan, retur beli.
     *
     * @param  bool  $allowNegative  hanya true bila pemanggil sudah memastikan izin owner.
     */
    public function decrease(
        Product $product,
        string|int|float $qty,
        MovementType $type,
        ?ProductUnit $unit = null,
        ?Model $reference = null,
        ?string $notes = null,
        ?User $user = null,
        bool $allowNegative = false,
    ): InventoryMovement {
        $qty = $this->positiveQty($qty, $product, $unit);

        return $this->apply($product, $unit, $qty, $qty->negated(), $type, $reference, null, $notes, $user, $allowNegative);
    }

    /**
     * Koreksi manual dengan alasan wajib. $qty bertanda: positif menambah, negatif mengurangi.
     */
    public function adjust(
        Product $product,
        string|int|float $qty,
        AdjustmentReason $reason,
        ?ProductUnit $unit = null,
        ?string $notes = null,
        ?User $user = null,
        bool $allowNegative = false,
    ): InventoryMovement {
        $signed = $this->decimal($qty);

        if ($signed->isZero()) {
            throw new InvalidStockQuantityException('Jumlah koreksi tidak boleh 0.');
        }

        if ($reason === AdjustmentReason::Other && blank($notes)) {
            throw new InvalidStockQuantityException('Catatan wajib diisi untuk alasan "Lainnya".');
        }

        $abs = $this->positiveQty((string) $signed->abs(), $product, $unit);

        return $this->apply($product, $unit, $abs, $signed->isNegative() ? $abs->negated() : $abs,
            MovementType::Adjustment, null, $reason, $notes, $user, $allowNegative);
    }

    /**
     * Opname: set stok sesuai hasil hitung fisik (dalam satuan dasar).
     * Selisihnya dicatat sebagai mutasi OPNAME, termasuk bila selisihnya 0 (bukti sudah dihitung).
     */
    public function opname(
        Product $product,
        string|int|float $countedBaseQty,
        ?string $notes = null,
        ?User $user = null,
    ): InventoryMovement {
        $counted = $this->decimal($countedBaseQty);

        if ($counted->isNegative()) {
            throw new InvalidStockQuantityException('Hasil hitung tidak boleh negatif.');
        }

        $baseUnit = $this->baseUnit($product);
        $this->assertQtyAllowed($counted, $baseUnit);

        return DB::transaction(function () use ($product, $baseUnit, $counted, $notes, $user) {
            $locked = $this->lock($product);
            $delta = $counted->minus($this->decimal($locked->current_stock));

            return $this->write($locked, $baseUnit, $counted, $delta, MovementType::Opname, null, null, $notes, $user);
        });
    }

    private function apply(
        Product $product,
        ?ProductUnit $unit,
        BigDecimal $qty,
        BigDecimal $signedQty,
        MovementType $type,
        ?Model $reference,
        ?AdjustmentReason $reason,
        ?string $notes,
        ?User $user,
        bool $allowNegative = false,
    ): InventoryMovement {
        $unit ??= $this->baseUnit($product);
        $delta = $signedQty->multipliedBy($this->decimal($unit->conversion_qty))->toScale(self::SCALE, RoundingMode::HALF_UP);

        return DB::transaction(function () use ($product, $unit, $qty, $delta, $type, $reference, $reason, $notes, $user, $allowNegative) {
            $locked = $this->lock($product);
            $before = $this->decimal($locked->current_stock);

            if (! $allowNegative && $delta->isNegative() && $before->plus($delta)->isNegative()) {
                throw new InsufficientStockException($locked, (string) $before, (string) $delta->abs());
            }

            return $this->write($locked, $unit, $qty, $delta, $type, $reference, $reason, $notes, $user);
        });
    }

    private function write(
        Product $locked,
        ProductUnit $unit,
        BigDecimal $qty,
        BigDecimal $delta,
        MovementType $type,
        ?Model $reference,
        ?AdjustmentReason $reason,
        ?string $notes,
        ?User $user,
    ): InventoryMovement {
        $before = $this->decimal($locked->current_stock);
        $after = $before->plus($delta)->toScale(self::SCALE);

        // Update langsung lewat query agar tidak bergantung pada $fillable / event model.
        Product::withTrashed()->whereKey($locked->getKey())->update(['current_stock' => (string) $after]);

        $movement = InventoryMovement::query()->create([
            'product_id' => $locked->getKey(),
            'product_unit_id' => $unit->getKey(),
            'unit_name' => $unit->unit?->name,
            'conversion_qty' => (string) $unit->conversion_qty,
            'movement_type' => $type,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'qty' => (string) $qty->toScale(self::SCALE),
            'qty_change' => (string) $delta->toScale(self::SCALE),
            'stock_before' => (string) $before->toScale(self::SCALE),
            'stock_after' => (string) $after,
            'reason' => $reason?->value,
            'notes' => $notes,
            'user_id' => ($user ?? Auth::user())?->getKey(),
        ]);

        return $movement;
    }

    private function lock(Product $product): Product
    {
        return Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
    }

    private function positiveQty(string|int|float $qty, Product $product, ?ProductUnit $unit): BigDecimal
    {
        $value = $this->decimal($qty);

        if (! $value->isPositive()) {
            throw new InvalidStockQuantityException('Jumlah harus lebih dari 0.');
        }

        $unit ??= $this->baseUnit($product);

        if ((int) $unit->product_id !== (int) $product->getKey()) {
            throw new InvalidStockQuantityException('Satuan tidak terdaftar untuk produk ini.');
        }

        $this->assertQtyAllowed($value, $unit);

        return $value;
    }

    private function assertQtyAllowed(BigDecimal $qty, ProductUnit $unit): void
    {
        if (! $unit->allow_decimal_qty && $qty->stripTrailingZeros()->getScale() > 0) {
            throw new InvalidStockQuantityException(sprintf(
                'Satuan %s tidak boleh pecahan (%s).',
                $unit->unit?->name ?? '',
                Product::formatQty((string) $qty),
            ));
        }
    }

    private function baseUnit(Product $product): ProductUnit
    {
        $unit = $product->baseUnit()->with('unit')->first();

        if (! $unit) {
            throw new InvalidStockQuantityException("Produk {$product->title} belum punya satuan dasar.");
        }

        return $unit;
    }

    private function decimal(string|int|float|null $value): BigDecimal
    {
        $value = is_float($value) ? number_format($value, self::SCALE, '.', '') : (string) ($value ?? '0');

        try {
            return BigDecimal::of($value === '' ? '0' : $value);
        } catch (\Brick\Math\Exception\MathException) {
            throw new InvalidStockQuantityException('Jumlah tidak valid.');
        }
    }
}
