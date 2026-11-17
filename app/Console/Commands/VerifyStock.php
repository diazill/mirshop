<?php

namespace App\Console\Commands;

use App\Models\Product;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cek integritas buku stok (hanya baca). Untuk tiap produk:
 * - current_stock = jumlah qty_change semua mutasinya,
 * - stock_after = stock_before + qty_change di tiap mutasi,
 * - stock_before sebuah mutasi = stock_after mutasi sebelumnya.
 * Exit code 1 bila ada selisih, jadi bisa dipasang di cron atau CI.
 */
class VerifyStock extends Command
{
    protected $signature = 'stock:verify {--product= : Hanya cek satu product_id}';

    protected $description = 'Cek apakah stok produk cocok dengan riwayat mutasinya';

    public function handle(): int
    {
        $sums = DB::table('inventory_movements')
            ->selectRaw('product_id, sum(qty_change) as total, count(*) as n')
            ->when($this->option('product'), fn ($q, $id) => $q->where('product_id', $id))
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $problems = [];

        Product::withTrashed()
            ->when($this->option('product'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->chunk(500, function ($products) use ($sums, &$problems): void {
                foreach ($products as $product) {
                    $expected = BigDecimal::of((string) ($sums[$product->id]->total ?? '0'))->toScale(3);
                    $actual = BigDecimal::of((string) $product->current_stock)->toScale(3);

                    if (! $expected->isEqualTo($actual)) {
                        $problems[] = [$product->id, $product->title, "stok {$actual}, total mutasi {$expected}"];
                    }
                }
            });

        $lastProduct = null;
        $lastAfter = null;

        DB::table('inventory_movements')
            ->select(['id', 'product_id', 'qty_change', 'stock_before', 'stock_after'])
            ->when($this->option('product'), fn ($q, $id) => $q->where('product_id', $id))
            ->orderBy('product_id')
            ->orderBy('id')
            ->chunk(1000, function ($movements) use (&$problems, &$lastProduct, &$lastAfter): void {
                foreach ($movements as $m) {
                    $before = BigDecimal::of((string) $m->stock_before);
                    $after = BigDecimal::of((string) $m->stock_after);

                    if (! $before->plus((string) $m->qty_change)->isEqualTo($after)) {
                        $problems[] = [$m->product_id, "mutasi #{$m->id}", "{$m->stock_before} + {$m->qty_change} ≠ {$m->stock_after}"];
                    }

                    if ($lastProduct === $m->product_id && ! $before->isEqualTo($lastAfter)) {
                        $problems[] = [$m->product_id, "mutasi #{$m->id}", "stok sebelum {$m->stock_before}, mutasi sebelumnya berakhir {$lastAfter}"];
                    }

                    $lastProduct = $m->product_id;
                    $lastAfter = $after;
                }
            });

        $movementCount = $sums->sum('n');

        if ($problems === []) {
            $this->info("Stok cocok: {$sums->count()} produk bermutasi, {$movementCount} mutasi diperiksa.");

            return self::SUCCESS;
        }

        $this->error(count($problems) . ' selisih ditemukan:');
        $this->table(['product_id', 'objek', 'masalah'], $problems);

        return self::FAILURE;
    }
}
