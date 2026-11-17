<?php

namespace Tests\Feature;

use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Skenario satu hari di toko pakan, lalu buku stok diaudit dengan stock:verify. */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_day_of_store_activity_keeps_the_ledger_consistent(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password',
            'role' => 'owner', 'is_active' => true,
        ]);
        $this->actingAs($owner);

        $stock = app(StockService::class);
        $purchases = app(PurchaseService::class);

        $feed = Product::query()->create(['title' => 'Pakan Ayam', 'price' => 9000, 'cost_price' => 0, 'weight_gram' => 1000]);
        $kg = $feed->units()->create(['unit_id' => Unit::query()->where('code', 'KG')->value('id'), 'is_base' => true, 'allow_decimal_qty' => true]);
        $sack = $feed->units()->create(['unit_id' => Unit::query()->where('code', 'KRG')->value('id'), 'conversion_qty' => 50]);

        $catFood = Product::query()->create(['title' => 'Makanan Kucing 1 kg', 'price' => 25000, 'cost_price' => 0, 'weight_gram' => 1000]);
        $pack = $catFood->units()->create(['unit_id' => Unit::query()->where('code', 'BKS')->value('id'), 'is_base' => true]);

        $supplier = Supplier::query()->create(['name' => 'PT Pakan', 'contact_person' => 'Budi', 'phone' => '0800']);

        $morning = Purchase::query()->create(['supplier_id' => $supplier->id, 'purchase_date' => now()]);
        $morning->purchaseItems()->create(['product_unit_id' => $sack->id, 'qty' => 4, 'buy_price' => 420000]);
        $morning->purchaseItems()->create(['product_unit_id' => $pack->id, 'qty' => 24, 'buy_price' => 20000]);
        $purchases->receive($morning);

        $stock->decrease($feed, 1, MovementType::Sale, $sack);
        $stock->decrease($feed, '2.5', MovementType::Sale, $kg);
        $stock->decrease($feed, '0.75', MovementType::Sale);
        $stock->decrease($catFood, 3, MovementType::Sale, $pack);
        $stock->increase($catFood, 1, MovementType::SaleReturn, $pack);
        $stock->adjust($feed, '-0.4', AdjustmentReason::Shrinkage);

        $wrong = Purchase::query()->create(['supplier_id' => $supplier->id, 'purchase_date' => now()]);
        $wrong->purchaseItems()->create(['product_unit_id' => $sack->id, 'qty' => 1, 'buy_price' => 420000]);
        $purchases->receive($wrong);
        $purchases->cancel($wrong, 'Salah kirim', $owner);

        $stock->opname($feed, '146');
        $stock->opname($catFood, 22);

        // 200 − 50 − 2,5 − 0,75 − 0,4 = 146,35 → opname 146
        $this->assertSame('146.000', $feed->refresh()->current_stock);
        $this->assertSame('22.000', $catFood->refresh()->current_stock);

        $this->artisan('stock:verify')
            ->expectsOutputToContain('Stok cocok: 2 produk bermutasi')
            ->assertSuccessful();

        // Seseorang mengubah stok langsung di database, melewati StockService.
        DB::table('products')->where('id', $feed->id)->update(['current_stock' => '150']);

        $this->artisan('stock:verify')
            ->expectsOutputToContain('selisih ditemukan')
            ->assertFailed();
    }
}
