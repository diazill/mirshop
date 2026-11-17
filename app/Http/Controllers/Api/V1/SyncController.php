<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sinkron aplikasi HP offline-first.
 *
 * Pagi (online): GET /sync/catalog tanpa `since` untuk data lengkap, simpan `server_time`.
 * Tombol Sinkron: POST /sync/transactions untuk nota offline, lalu GET /sync/catalog?since=<server_time
 * terakhir> untuk perubahan katalog dan stok. Terapkan `deletions` dulu, baru data lainnya.
 */
class SyncController extends Controller
{
    public const MAX_SALES_PER_SYNC = 100;

    public const MAX_LINES_PER_SYNC = 2000;

    public const MAX_UNIT_PRICE = 100_000_000;

    public function catalog(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['nullable', 'date', 'before_or_equal:now']]);

        // Diambil sebelum query dan dimundurkan sedikit: perubahan yang sedang di-commit saat ini
        // ikut terbawa lagi di sinkron berikutnya (data ganda aman, HP melakukan upsert).
        $serverTime = now()->subSeconds(10);
        $since = isset($data['since']) ? CarbonImmutable::parse($data['since']) : null;
        $changed = fn ($query) => $since ? $query->where('updated_at', '>=', $since) : $query;

        $products = $changed(Product::query()->when($since, fn ($q) => $q->withTrashed()))
            ->with(['category:id,name', 'brand:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (Product $p): array => [
                'id' => $p->id,
                'sku' => $p->sku,
                'title' => $p->title,
                'category' => $p->category?->name,
                'brand' => $p->brand?->name,
                'price' => $p->price,
                'current_stock' => $p->current_stock,
                'min_stock' => $p->min_stock,
                'is_active' => $p->is_active,
                'is_deleted' => $p->trashed(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ]);

        $productUnits = $changed(ProductUnit::query()->with('unit:id,name'))
            ->when(! $since, fn ($q) => $q->whereHas('product'))
            ->orderBy('id')
            ->get()
            ->map(fn (ProductUnit $u): array => [
                'id' => $u->id,
                'product_id' => $u->product_id,
                'unit_id' => $u->unit_id,
                'unit_name' => $u->unit?->name,
                'conversion_qty' => $u->conversion_qty,
                'is_base' => $u->is_base,
                'barcode' => $u->barcode,
                'allow_decimal_qty' => $u->allow_decimal_qty,
                'is_sellable' => $u->is_sellable,
                'is_purchasable' => $u->is_purchasable,
            ]);

        $prices = $changed(ProductPrice::query())
            ->orderBy('id')
            ->get(['id', 'product_unit_id', 'price_type', 'min_qty', 'price']);

        $units = $changed(Unit::query())
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'allow_decimal_qty', 'is_active']);

        $deletions = $since
            ? DB::table('sync_deletions')
                ->where('deleted_at', '>=', $since)
                ->orderBy('id')
                ->get(['table_name as table', 'record_id as id'])
            : collect();

        return response()->json([
            'server_time' => $serverTime->toIso8601String(),
            'full' => $since === null,
            'deletions' => $deletions,
            'units' => $units,
            'products' => $products,
            'product_units' => $productUnits,
            'product_prices' => $prices,
        ]);
    }

    public function transactions(Request $request, TransactionService $service): JsonResponse
    {
        $data = $request->validate([
            'transactions' => ['required', 'array', 'min:1', 'max:' . self::MAX_SALES_PER_SYNC],
            'transactions.*.client_id' => ['required', 'string', 'max:100'],
            'transactions.*.invoice_no' => ['required', 'string', 'max:40'],
            'transactions.*.occurred_at' => ['required', 'date'],
            'transactions.*.payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'transactions.*.paid_amount' => ['nullable', 'integer', 'min:0', 'max:' . TransactionService::MAX_AMOUNT],
            'transactions.*.notes' => ['nullable', 'string', 'max:1000'],
            'transactions.*.cashier_id' => ['nullable', 'integer'],
            'transactions.*.items' => ['required', 'array', 'min:1', 'max:200'],
            'transactions.*.items.*' => ['array'],
            'transactions.*.items.*.product_unit_id' => ['required', 'integer'],
            'transactions.*.items.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'transactions.*.items.*.price' => ['required', 'integer', 'min:0', 'max:' . self::MAX_UNIT_PRICE],
            'transactions.*.items.*.price_type' => ['nullable', Rule::in(TransactionService::CASHIER_PRICE_TYPES)],
        ]);

        // Batasi beban satu request: kirim sisanya di request berikutnya.
        $lineCount = collect($data['transactions'])->sum(fn (array $sale): int => count($sale['items']));

        if ($lineCount > self::MAX_LINES_PER_SYNC) {
            return response()->json([
                'code' => 'SINKRON_TERLALU_BESAR',
                'message' => 'Maksimal ' . self::MAX_LINES_PER_SYNC . " baris barang per sinkron, dikirim {$lineCount}. Kirim bertahap.",
            ], 422);
        }

        $results = $service->syncOffline($request->attributes->get('device'), $request->user(), $data['transactions']);

        $summary = collect($results)->countBy('status');

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'summary' => [
                'created' => $summary->get('created', 0),
                'duplicate' => $summary->get('duplicate', 0),
                'rejected' => $summary->get('rejected', 0),
            ],
            'data' => $results,
        ]);
    }
}
