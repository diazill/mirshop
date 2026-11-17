<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Exceptions\BusinessException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\TransactionException;
use App\Models\Device;
use App\Models\ProductUnit;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penjualan kasir, dalam dua jalur:
 *
 * - Online (createSale): server menghitung harga dan menolak bila stok tidak cukup.
 * - Sinkron offline (syncOffline): nota sudah terjadi di toko, jadi server mencatatnya apa adanya
 *   (harga kasir, waktu kejadian, nomor nota dari HP) dan menandai nota untuk ditinjau owner bila
 *   harganya beda dari harga resmi atau stoknya jadi minus.
 *
 * Kedua jalur memakai persist(): satu nota tersimpan utuh dalam satu transaksi DB atau tidak sama sekali.
 */
class TransactionService
{
    /** Jenis harga yang boleh dipilih kasir. "langganan" menunggu modul pelanggan. */
    public const CASHIER_PRICE_TYPES = ['eceran', 'grosir'];

    /** Nota offline lebih tua dari ini ditolak (mencegah nota fiktif di hari yang sudah tutup buku). */
    public const OFFLINE_MAX_AGE_DAYS = 3;

    /** Nota offline lebih tua dari ini tetap diterima tetapi ditandai "Sinkron terlambat". */
    public const OFFLINE_LATE_HOURS = 24;

    /** Batas nominal agar tidak melewati kolom integer Postgres (2.147.483.647). */
    public const MAX_AMOUNT = 1_000_000_000;

    public function __construct(
        private readonly StockService $stock,
        private readonly PricingService $pricing,
    ) {
    }

    /**
     * @param  array<int, array{product_unit_id: int, qty: string|int|float, price_type?: string}>  $lines
     * @return Transaction  wasRecentlyCreated = false bila ini pengulangan dengan Idempotency-Key yang sama
     */
    public function createSale(
        User $cashier,
        array $lines,
        PaymentMethod $method,
        ?int $paidAmount = null,
        ?string $idempotencyKey = null,
        ?string $notes = null,
    ): Transaction {
        $hash = hash('sha256', json_encode([$lines, $method->value, $paidAmount, $notes]));

        if ($idempotencyKey && $existing = $this->findReplay($cashier, $idempotencyKey, $hash)) {
            return $existing;
        }

        $priced = $this->priceLines($lines, offline: false);
        $total = $this->total($priced);
        [$paid, $change] = $this->settle($method, $total, $paidAmount);

        try {
            return $this->persist($priced, [
                'cashier' => $cashier,
                'method' => $method,
                'total' => $total,
                'paid' => $paid,
                'change' => $change,
                'notes' => $notes,
                'key' => $idempotencyKey,
                'hash' => $hash,
                'occurred_at' => now(),
                'invoice_no' => null,
                'source' => 'online',
                'device' => null,
                'synced_by' => null,
                'late_sync' => false,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Dua permintaan dengan kunci sama tiba bersamaan: yang kalah mengembalikan hasil pemenang.
            if ($idempotencyKey && $existing = $this->findReplay($cashier, $idempotencyKey, $hash)) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * Terima nota yang dibuat offline di HP. Tiap nota diproses terpisah dan berurutan menurut
     * waktu kejadian; satu nota gagal tidak menggagalkan yang lain.
     *
     * @param  array<int, array<string, mixed>>  $sales
     * @return array<int, array<string, mixed>> hasil per nota: created | duplicate | rejected
     */
    public function syncOffline(Device $device, User $syncedBy, array $sales): array
    {
        $results = collect($sales)
            ->sortBy(fn (array $sale) => (string) ($sale['occurred_at'] ?? ''))
            ->map(fn (array $sale): array => $this->syncOne($device, $syncedBy, $sale))
            ->values()
            ->all();

        $device->forceFill(['last_synced_at' => now(), 'last_synced_by' => $syncedBy->getKey()])->save();

        return $results;
    }

    /** Batalkan penjualan: hanya owner, stok dikembalikan sebanyak yang dulu keluar. */
    public function cancel(Transaction $transaction, string $reason, User $user): Transaction
    {
        if (! $user->hasRole('owner')) {
            throw new TransactionException('Hanya owner yang bisa membatalkan penjualan.');
        }

        if (blank($reason)) {
            throw new TransactionException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($transaction, $reason, $user) {
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw new TransactionException("Penjualan {$locked->invoice_no} sudah dibatalkan.");
            }

            $items = $locked->transactionItems()->with('product')->orderBy('product_id')->orderBy('id')->get();

            foreach ($items as $item) {
                $this->stock->increase(
                    product: $item->product,
                    qty: $item->base_qty,
                    type: MovementType::SaleCancel,
                    reference: $locked,
                    notes: $reason,
                    user: $user,
                );
            }

            $locked->forceFill([
                'status' => TransactionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancel_reason' => $reason,
            ])->save();

            return $locked;
        });
    }

    /** Owner/admin menandai nota offline yang ditandai sudah diperiksa. */
    public function markReviewed(Transaction $transaction, User $user): Transaction
    {
        $transaction->forceFill(['needs_review' => false, 'reviewed_at' => now(), 'reviewed_by' => $user->getKey()])->save();

        return $transaction;
    }

    private function syncOne(Device $device, User $syncedBy, array $sale): array
    {
        $result = ['client_id' => $sale['client_id'] ?? null, 'invoice_no' => $sale['invoice_no'] ?? null];
        $cashier = null;
        $hash = hash('sha256', json_encode($sale));

        try {
            $cashier = $this->offlineCashier($sale, $syncedBy, $device);
            $occurredAt = $this->offlineTime($sale['occurred_at'] ?? null);
            $lateSync = $occurredAt->isBefore(now()->subHours(self::OFFLINE_LATE_HOURS));

            if (! str_starts_with((string) $sale['invoice_no'], $device->code . '-')) {
                throw new TransactionException("Nomor nota harus diawali kode perangkat {$device->code}-.");
            }

            if ($existing = $this->findReplay($cashier, $sale['client_id'], $hash)) {
                return $result + ['status' => 'duplicate'] + $this->resultData($existing);
            }

            $priced = $this->priceLines($sale['items'], offline: true);
            $total = $this->total($priced);
            $method = PaymentMethod::from($sale['payment_method']);
            [$paid, $change] = $this->settle($method, $total, $sale['paid_amount'] ?? null);

            $transaction = $this->persist($priced, [
                'cashier' => $cashier,
                'method' => $method,
                'total' => $total,
                'paid' => $paid,
                'change' => $change,
                'notes' => $sale['notes'] ?? null,
                'key' => $sale['client_id'],
                'hash' => $hash,
                'occurred_at' => $occurredAt,
                'invoice_no' => $sale['invoice_no'],
                'source' => 'offline',
                'device' => $device,
                'synced_by' => $syncedBy,
                'late_sync' => $lateSync,
            ]);

            return $result + ['status' => 'created'] + $this->resultData($transaction);
        } catch (UniqueConstraintViolationException) {
            if ($cashier && $existing = Transaction::query()->where('user_id', $cashier->id)->where('idempotency_key', $sale['client_id'])->first()) {
                return $result + ['status' => 'duplicate'] + $this->resultData($existing);
            }

            return $result + [
                'status' => 'rejected',
                'code' => 'NOMOR_NOTA_BENTROK',
                'message' => "Nomor nota {$sale['invoice_no']} sudah dipakai nota lain.",
            ];
        } catch (BusinessException $exception) {
            return $result + ['status' => 'rejected', 'code' => $exception->errorCode(), 'message' => $exception->getMessage()];
        }
    }

    /**
     * Simpan nota, item, dan kurangi stok dalam satu transaksi DB.
     *
     * @param  Collection<int, array<string, mixed>>  $priced
     * @param  array<string, mixed>  $sale
     */
    private function persist(Collection $priced, array $sale): Transaction
    {
        $offline = $sale['source'] === 'offline';
        $priceMismatch = $priced->contains(fn (array $line): bool => $line['official'] !== null && $line['official'] !== $line['price']);
        $belowCost = $offline && $priced->contains(fn (array $line): bool => $line['price'] < $line['cost']);
        $lateSync = $sale['late_sync'];

        return DB::transaction(function () use ($priced, $sale, $offline, $priceMismatch, $belowCost, $lateSync) {
            /** @var User $cashier */
            $cashier = $sale['cashier'];

            $transaction = Transaction::query()->create([
                'invoice_no' => $sale['invoice_no'] ?? 'TMP-' . bin2hex(random_bytes(8)),
                'occurred_at' => $sale['occurred_at'],
                'channel' => 'pos',
                'source' => $sale['source'],
                'status' => TransactionStatus::Paid,
                'user_id' => $cashier->getKey(),
                'device_id' => $sale['device']?->getKey(),
                'synced_by' => $sale['synced_by']?->getKey(),
                'cashier_name' => $cashier->name,
                'total_amount' => $sale['total'],
                'payment_method' => $sale['method'],
                'paid_amount' => $sale['paid'],
                'change_amount' => $sale['change'],
                'notes' => $sale['notes'],
                'idempotency_key' => $sale['key'],
                'request_hash' => $sale['key'] ? $sale['hash'] : null,
                'price_mismatch' => $priceMismatch,
                'below_cost' => $belowCost,
                'late_sync' => $lateSync,
            ]);

            if ($sale['invoice_no'] === null) {
                // Nomor dari id: unik tanpa menghitung nomor terakhir (aman untuk banyak kasir).
                $transaction->forceFill([
                    'invoice_no' => 'INV-' . now()->format('Ymd') . '-' . str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT),
                ])->save();
            }

            $stockConflict = false;

            // Urut product_id agar urutan row lock konsisten antar kasir (hindari deadlock).
            foreach ($priced->sortBy(fn (array $line) => [$line['unit']->product_id, $line['index']]) as $line) {
                /** @var ProductUnit $unit */
                $unit = $line['unit'];

                $transaction->transactionItems()->create([
                    'product_id' => $unit->product_id,
                    'product_unit_id' => $unit->id,
                    'unit_name' => $unit->unit->name,
                    'conversion_qty' => (string) $unit->conversion_qty,
                    'qty' => $line['qty'],
                    'base_qty' => $unit->toBaseQty($line['qty']),
                    'price_type' => $line['price_type'],
                    'price_at_time' => $line['price'],
                    'official_price' => $line['official'],
                    'cost_price_at_time' => $line['cost'],
                    'subtotal' => $line['subtotal'],
                ]);

                // Nota offline sudah terjadi: stok boleh minus, nota ditandai untuk ditinjau.
                $movement = $this->stock->decrease(
                    product: $unit->product,
                    qty: $line['qty'],
                    type: MovementType::Sale,
                    unit: $unit,
                    reference: $transaction,
                    user: $cashier,
                    allowNegative: $offline,
                );

                $stockConflict = $stockConflict || BigDecimal::of((string) $movement->stock_after)->isNegative();
            }

            if ($stockConflict || $priceMismatch || $belowCost || $lateSync) {
                $transaction->forceFill([
                    'stock_conflict' => $stockConflict,
                    'needs_review' => true,
                ])->save();
            }

            return $transaction;
        });
    }

    private function findReplay(User $cashier, string $key, string $requestHash): ?Transaction
    {
        $existing = Transaction::query()
            ->where('user_id', $cashier->getKey())
            ->where('idempotency_key', $key)
            ->first();

        if ($existing && ! hash_equals((string) $existing->request_hash, $requestHash)) {
            throw new IdempotencyConflictException();
        }

        return $existing;
    }

    /**
     * Online: harga dari server, produk harus aktif dan satuan bisa dijual.
     * Offline: harga dari kasir (dibandingkan dengan harga resmi), produk nonaktif/terhapus tetap
     * diterima karena barang sudah keluar.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function priceLines(array $lines, bool $offline): Collection
    {
        if ($lines === []) {
            throw new TransactionException('Keranjang kosong.');
        }

        $units = ProductUnit::query()
            ->with(['product' => fn ($q) => $offline ? $q->withTrashed() : $q, 'unit', 'prices'])
            ->whereIn('id', collect($lines)->pluck('product_unit_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return collect($lines)->values()->map(function (array $line, int $index) use ($units, $offline): array {
            $unit = $units->get($line['product_unit_id'] ?? null);
            $qty = $this->qty($line['qty'] ?? null);
            $priceType = $line['price_type'] ?? 'eceran';

            if (! $unit || ! $unit->product) {
                throw new TransactionException('Produk pada baris ' . ($index + 1) . ' tidak ditemukan.');
            }

            $name = "{$unit->product->title} ({$unit->unit->name})";

            if (! $offline && ! $unit->product->is_active) {
                throw new TransactionException("{$unit->product->title} sedang tidak dijual.");
            }

            if (! $offline && ! $unit->is_sellable) {
                throw new TransactionException("{$name} tidak bisa dijual dalam satuan ini.");
            }

            if (! in_array($priceType, self::CASHIER_PRICE_TYPES, true)) {
                throw new TransactionException("Jenis harga {$priceType} tidak tersedia di kasir.");
            }

            if (! $qty->isPositive()) {
                throw new TransactionException("Jumlah {$name} harus lebih dari 0.");
            }

            if (! $unit->allow_decimal_qty && $qty->stripTrailingZeros()->getScale() > 0) {
                throw new TransactionException("{$name} tidak boleh dijual pecahan.");
            }

            $official = $this->pricing->unitPrice($unit, (string) $qty, $priceType);
            $price = $offline ? (int) $line['price'] : $official;
            $subtotal = $qty->multipliedBy($price)->toScale(0, RoundingMode::HALF_UP);

            if ($price < 0 || $subtotal->isGreaterThan(self::MAX_AMOUNT)) {
                throw new TransactionException("Nilai {$name} di luar batas wajar.");
            }

            return [
                'index' => $index,
                'unit' => $unit,
                'qty' => (string) $qty->toScale(3),
                'price_type' => $priceType,
                'price' => $price,
                'official' => $offline ? $official : null,
                'cost' => $this->pricing->unitCost($unit),
                'subtotal' => $subtotal->toInt(),
            ];
        });
    }

    private function total(Collection $priced): int
    {
        $total = (int) $priced->sum('subtotal');

        if ($total > self::MAX_AMOUNT) {
            throw new TransactionException('Total nota di luar batas wajar.');
        }

        return $total;
    }

    /** @return array{0: int, 1: int} [dibayar, kembalian] */
    private function settle(PaymentMethod $method, int $total, ?int $paidAmount): array
    {
        if ($method !== PaymentMethod::Cash) {
            return [$total, 0];
        }

        if ($paidAmount !== null && $paidAmount > self::MAX_AMOUNT) {
            throw new TransactionException('Jumlah bayar di luar batas wajar.');
        }

        if ($paidAmount === null || $paidAmount < $total) {
            throw new TransactionException(sprintf(
                'Uang tunai kurang. Total %s, dibayar %s.',
                number_format($total, 0, ',', '.'),
                number_format((int) $paidAmount, 0, ',', '.'),
            ));
        }

        return [$paidAmount, $paidAmount - $total];
    }

    /**
     * Kasir nota offline: cashier_id dari HP (bila HP dipakai bergantian) atau user yang menyinkronkan.
     * cashier_id hanya sah untuk user yang pernah login di perangkat ini, agar kasir tidak bisa
     * mencatat nota atas nama orang lain.
     */
    private function offlineCashier(array $sale, User $syncedBy, Device $device): User
    {
        if (empty($sale['cashier_id']) || (int) $sale['cashier_id'] === $syncedBy->id) {
            return $syncedBy;
        }

        $cashier = User::query()->find($sale['cashier_id']);

        if (! $cashier || ! $cashier->hasRole('owner', 'admin', 'kasir') || ! $device->hasUser($cashier->id)) {
            throw new TransactionException("Kasir pada nota belum pernah login di perangkat {$device->code}.");
        }

        return $cashier;
    }

    private function offlineTime(?string $value): CarbonImmutable
    {
        try {
            $time = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw new TransactionException('Waktu nota tidak valid.');
        }

        // Toleransi jam HP yang sedikit maju.
        if ($time->isAfter(now()->addMinutes(10))) {
            throw new TransactionException('Waktu nota ada di masa depan. Periksa jam di HP.');
        }

        if ($time->isBefore(now()->subDays(self::OFFLINE_MAX_AGE_DAYS))) {
            throw new TransactionException('Nota lebih tua dari ' . self::OFFLINE_MAX_AGE_DAYS . ' hari. Hubungi owner.');
        }

        return $time;
    }

    private function resultData(Transaction $transaction): array
    {
        return [
            'transaction_id' => $transaction->id,
            'invoice_no' => $transaction->invoice_no,
            'total_amount' => $transaction->total_amount,
            'needs_review' => $transaction->needs_review,
            'price_mismatch' => $transaction->price_mismatch,
            'stock_conflict' => $transaction->stock_conflict,
            'below_cost' => $transaction->below_cost,
            'late_sync' => $transaction->late_sync,
        ];
    }

    private function qty(mixed $value): BigDecimal
    {
        try {
            return BigDecimal::of(is_float($value) ? number_format($value, 3, '.', '') : (string) $value);
        } catch (\Brick\Math\Exception\MathException) {
            throw new TransactionException('Jumlah tidak valid.');
        }
    }
}
