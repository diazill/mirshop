<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Exceptions\IdempotencyConflictException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Opname dan koreksi stok dari aplikasi HP (online). Membungkus StockService dengan
 * Idempotency-Key: permintaan yang dikirim ulang karena sinyal putus mengembalikan mutasi
 * yang sama tanpa mengubah stok lagi. Pola sama dengan penjualan POS.
 */
class WarehouseStockService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /** @return array{0: InventoryMovement, 1: bool} [mutasi, replayed] */
    public function opname(
        User $user,
        string $key,
        Product $product,
        string $countedBaseQty,
        ?string $expectedBaseQty,
        AdjustmentReason $reason,
        ?string $notes,
    ): array {
        $hash = $this->hash('opname', [$product->id, $countedBaseQty, $expectedBaseQty, $reason->value, $notes]);

        return $this->once($user, $key, $hash, fn () => $this->stock->opname(
            product: $product,
            countedBaseQty: $countedBaseQty,
            notes: $notes,
            user: $user,
            expectedBaseQty: $expectedBaseQty,
            reason: $reason,
            movementAttributes: ['idempotency_key' => $key, 'request_hash' => $hash],
        ));
    }

    /** @return array{0: InventoryMovement, 1: bool} [mutasi, replayed] */
    public function adjust(
        User $user,
        string $key,
        Product $product,
        ?ProductUnit $unit,
        string $qty,
        AdjustmentReason $reason,
        ?string $notes,
    ): array {
        $hash = $this->hash('adjust', [$product->id, $unit?->id, $qty, $reason->value, $notes]);

        return $this->once($user, $key, $hash, fn () => $this->stock->adjust(
            product: $product,
            qty: $qty,
            reason: $reason,
            unit: $unit,
            notes: $notes,
            user: $user,
            allowNegative: false,
            movementAttributes: ['idempotency_key' => $key, 'request_hash' => $hash],
        ));
    }

    /** @return array{0: InventoryMovement, 1: bool} */
    private function once(User $user, string $key, string $hash, callable $operation): array
    {
        if ($existing = $this->replay($user, $key, $hash)) {
            return [$existing, true];
        }

        try {
            return [$operation(), false];
        } catch (UniqueConstraintViolationException $exception) {
            // Dua kiriman dengan kunci sama tiba bersamaan: yang kalah memakai hasil pemenang.
            if ($existing = $this->replay($user, $key, $hash)) {
                return [$existing, true];
            }

            throw $exception;
        }
    }

    private function replay(User $user, string $key, string $hash): ?InventoryMovement
    {
        $existing = InventoryMovement::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing && ! hash_equals((string) $existing->request_hash, $hash)) {
            throw new IdempotencyConflictException();
        }

        return $existing;
    }

    private function hash(string $operation, array $payload): string
    {
        return hash('sha256', json_encode([$operation, ...$payload]));
    }
}
