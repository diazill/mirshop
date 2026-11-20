<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdjustmentReason;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\WarehouseStockService;
use Brick\Math\BigDecimal;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Gudang', 'Kelola barang dari aplikasi HP: barcode, stok, foto. Online.', weight: 5)]
class WarehouseStockController extends Controller
{
    public function __construct(private readonly WarehouseStockService $warehouse)
    {
    }

    /**
     * Isi stok hasil hitung (opname).
     *
     * Dipakai juga untuk stok awal. `expected_base_qty` = stok yang dilihat staf saat mulai menghitung;
     * server hanya menerapkan selisih hitung − stok yang dilihat, jadi penjualan di antaranya tidak terhapus.
     * Hasil langsung berlaku dan tercatat siapa, kapan, dan alasannya. Jumlah dalam satuan dasar.
     * Kirim ulang dengan `Idempotency-Key` yang sama mengembalikan hasil asli (`replayed: true`).
     * Peran: owner, admin, gudang.
     */
    #[HeaderParameter('Idempotency-Key', 'UUID unik per kiriman, dibuat aplikasi. Wajib.', required: true, example: '1f6c2a7e-9d0b-4d6e-8a3c-5b7e2f0c4d11')]
    public function opname(Request $request): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            /** Hasil hitung fisik dalam satuan dasar. */
            'counted_base_qty' => ['required', 'numeric', 'min:0', 'max:9999999'],
            /** Stok sistem yang terlihat di HP saat mulai menghitung. */
            'expected_base_qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'notes' => ['nullable', 'string', 'max:1000', 'required_if:reason,' . AdjustmentReason::Other->value],
        ], $this->messages());

        $product = Product::query()->findOrFail($data['product_id']);

        [$movement, $replayed] = $this->warehouse->opname(
            user: $request->user(),
            key: $key,
            product: $product,
            countedBaseQty: $this->normalize($data['counted_base_qty']),
            expectedBaseQty: isset($data['expected_base_qty']) ? $this->normalize($data['expected_base_qty']) : null,
            reason: AdjustmentReason::from($data['reason']),
            notes: $data['notes'] ?? null,
        );

        return $this->result($product, $movement, $replayed);
    }

    /**
     * Koreksi stok (+/−).
     *
     * `qty` bertanda: positif menambah, negatif mengurangi, dalam satuan `product_unit_id`
     * (kosong = satuan dasar). Stok tidak boleh jadi minus (`STOK_TIDAK_CUKUP`).
     * Kirim ulang dengan `Idempotency-Key` yang sama mengembalikan hasil asli (`replayed: true`).
     * Peran: owner, admin, gudang.
     */
    #[HeaderParameter('Idempotency-Key', 'UUID unik per kiriman, dibuat aplikasi. Wajib.', required: true, example: '9a3e5c1b-7f2d-4b8a-a6e4-0c2d9f1b3e57')]
    public function adjust(Request $request): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'product_unit_id' => ['nullable', 'integer'],
            /** Bertanda: "-2" mengurangi 2, "5" menambah 5. */
            'qty' => ['required', 'numeric', 'not_in:0', 'min:-99999', 'max:99999'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'notes' => ['nullable', 'string', 'max:1000', 'required_if:reason,' . AdjustmentReason::Other->value],
        ], $this->messages() + ['qty.not_in' => 'Jumlah koreksi tidak boleh 0.']);

        $product = Product::query()->findOrFail($data['product_id']);
        $unit = isset($data['product_unit_id'])
            ? ProductUnit::query()->with('unit')->where('product_id', $product->id)->findOrFail($data['product_unit_id'])
            : null;

        [$movement, $replayed] = $this->warehouse->adjust(
            user: $request->user(),
            key: $key,
            product: $product,
            unit: $unit,
            qty: $this->normalize($data['qty']),
            reason: AdjustmentReason::from($data['reason']),
            notes: $data['notes'] ?? null,
        );

        return $this->result($product, $movement, $replayed);
    }

    private function idempotencyKey(Request $request): string|JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 100) {
            return response()->json([
                'code' => 'IDEMPOTENCY_KEY_WAJIB',
                'message' => 'Header Idempotency-Key wajib diisi (maks. 100 karakter), satu nilai unik per kiriman.',
            ], 422);
        }

        return $key;
    }

    private function result(Product $product, InventoryMovement $movement, bool $replayed): JsonResponse
    {
        $product->refresh()->loadMissing('baseUnit.unit');

        return response()->json([
            'replayed' => $replayed,
            'data' => [
                'product_id' => $product->id,
                'current_stock' => $product->current_stock,
                'base_unit_name' => $product->baseUnit?->unit?->name,
                'movement' => [
                    'id' => $movement->id,
                    'type' => $movement->movement_type->value,
                    'reason' => $movement->reason,
                    'unit_name' => $movement->unit_name,
                    'qty' => $movement->qty,
                    'qty_change' => $movement->qty_change,
                    'stock_after' => $movement->stock_after,
                    'created_at' => $movement->created_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /** "37", "37.0" dan 37 dianggap sama, termasuk untuk pengecekan kirim ulang. */
    private function normalize(string|int|float $value): string
    {
        return (string) BigDecimal::of(is_float($value) ? number_format($value, 3, '.', '') : (string) $value)->toScale(3);
    }

    private function messages(): array
    {
        return [
            'notes.required_if' => 'Catatan wajib diisi untuk alasan "Lainnya".',
            'reason.required' => 'Alasan wajib dipilih.',
        ];
    }
}
