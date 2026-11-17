<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\TransactionStatus;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Filament\Resources\TransactionResource\Pages\ViewTransaction;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Services\PricingService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class PosTransactionTest extends TestCase
{
    use RefreshDatabase;

    private Product $feed;
    private ProductUnit $kg;
    private ProductUnit $sack;
    private Product $catFood;
    private ProductUnit $pack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = Product::query()->create(['title' => 'Pakan Ayam', 'price' => 9000, 'cost_price' => 8000, 'weight_gram' => 1000]);
        $this->kg = $this->feed->units()->create([
            'unit_id' => $this->unitId('KG'), 'is_base' => true, 'allow_decimal_qty' => true, 'barcode' => 'KG-001',
        ]);
        $this->sack = $this->feed->units()->create([
            'unit_id' => $this->unitId('KRG'), 'conversion_qty' => 50, 'barcode' => '8990000000050',
        ]);
        $this->sack->prices()->create(['price_type' => 'eceran', 'min_qty' => 1, 'price' => 420000]);
        $this->sack->prices()->create(['price_type' => 'grosir', 'min_qty' => 5, 'price' => 410000]);

        $this->catFood = Product::query()->create(['title' => 'Makanan Kucing 1 kg', 'price' => 25000, 'cost_price' => 20000, 'weight_gram' => 1000]);
        $this->pack = $this->catFood->units()->create(['unit_id' => $this->unitId('BKS'), 'is_base' => true]);

        $this->actingAs($this->user('owner', 'Gudang Awal'));
        app(StockService::class)->increase($this->feed, 10, MovementType::Purchase, $this->sack); // 500 kg
        app(StockService::class)->increase($this->catFood, 20, MovementType::Purchase);
    }

    public function test_pricing_uses_tier_then_base_price(): void
    {
        $pricing = app(PricingService::class);

        $this->assertSame(420000, $pricing->unitPrice($this->sack, 1));
        $this->assertSame(420000, $pricing->unitPrice($this->sack, 4, 'grosir'));   // grosir mulai 5 → jatuh ke eceran
        $this->assertSame(410000, $pricing->unitPrice($this->sack, 5, 'grosir'));
        $this->assertSame(9000, $pricing->unitPrice($this->kg, '0.5'));               // tanpa harga khusus → harga dasar
        $this->assertSame(400000, $pricing->unitCost($this->sack));                    // 8.000 × 50
    }

    public function test_cashier_sells_mixed_units_with_cash_and_change(): void
    {
        $cashier = $this->loginAs('kasir');

        $response = $this->postSale([
            'items' => [
                ['product_unit_id' => $this->sack->id, 'qty' => 2],
                ['product_unit_id' => $this->kg->id, 'qty' => '2.5'],
                ['product_unit_id' => $this->pack->id, 'qty' => 3],
            ],
            'payment_method' => 'tunai',
            'paid_amount' => 1000000,
        ]);

        // 2 × 420.000 + 2,5 × 9.000 + 3 × 25.000 = 937.500
        $response->assertCreated()
            ->assertJsonPath('data.total_amount', 937500)
            ->assertJsonPath('data.change_amount', 62500)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.cashier_name', $cashier->name)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Pakan Ayam');

        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{6}$/', $response->json('data.invoice_no'));
        $this->assertSame('397.500', $this->feed->refresh()->current_stock); // 500 − 100 − 2,5
        $this->assertSame('17.000', $this->catFood->refresh()->current_stock);

        $transaction = Transaction::query()->firstOrFail();
        $this->assertSame($cashier->id, $transaction->user_id);
        $this->assertSame(3, $transaction->inventoryMovements()->where('movement_type', MovementType::Sale)->count());

        $sackLine = $transaction->transactionItems()->where('product_unit_id', $this->sack->id)->firstOrFail();
        $this->assertSame('Karung', $sackLine->unit_name);
        $this->assertSame('100.000', $sackLine->base_qty);
        $this->assertSame(400000, $sackLine->cost_price_at_time);
    }

    public function test_wholesale_price_applies_from_minimum_quantity(): void
    {
        $this->loginAs('kasir');

        $this->postSale([
            'items' => [['product_unit_id' => $this->sack->id, 'qty' => 5, 'price_type' => 'grosir']],
            'payment_method' => 'transfer',
        ])->assertCreated()
            ->assertJsonPath('data.total_amount', 2050000)
            ->assertJsonPath('data.paid_amount', 2050000)
            ->assertJsonPath('data.items.0.price_type', 'grosir');
    }

    public function test_retry_with_same_idempotency_key_does_not_sell_twice(): void
    {
        $this->loginAs('kasir');
        $body = ['items' => [['product_unit_id' => $this->pack->id, 'qty' => 2]], 'payment_method' => 'qris'];

        $first = $this->postSale($body, 'kunci-123')->assertCreated();
        $retry = $this->postSale($body, 'kunci-123')->assertOk();

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('18.000', $this->catFood->refresh()->current_stock);

        $this->postSale(['items' => [['product_unit_id' => $this->pack->id, 'qty' => 5]], 'payment_method' => 'qris'], 'kunci-123')
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_BENTROK');
    }

    public function test_idempotency_key_is_required(): void
    {
        $this->loginAs('kasir');

        $this->postJson('/api/v1/pos/transactions', ['items' => [], 'payment_method' => 'tunai'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_WAJIB');
    }

    public function test_insufficient_stock_rolls_back_the_whole_sale(): void
    {
        $this->loginAs('kasir');

        $this->postSale([
            'items' => [
                ['product_unit_id' => $this->pack->id, 'qty' => 2],
                ['product_unit_id' => $this->sack->id, 'qty' => 11], // butuh 550 kg, ada 500
            ],
            'payment_method' => 'transfer',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'STOK_TIDAK_CUKUP')
            ->assertJsonPath('details.product_id', $this->feed->id);

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('20.000', $this->catFood->refresh()->current_stock);
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', MovementType::Sale)->count());
    }

    public function test_business_rules_reject_invalid_sales(): void
    {
        $this->loginAs('kasir');

        $cases = [
            'pecahan karung' => [['product_unit_id' => $this->sack->id, 'qty' => '0.5'], 'tunai', 999999999],
            'uang kurang' => [['product_unit_id' => $this->pack->id, 'qty' => 1], 'tunai', 1000],
            'harga langganan' => [['product_unit_id' => $this->pack->id, 'qty' => 1, 'price_type' => 'langganan'], 'qris', null],
            'satuan tidak ada' => [['product_unit_id' => 999999, 'qty' => 1], 'qris', null],
        ];

        foreach ($cases as $label => [$item, $method, $paid]) {
            $response = $this->postSale(['items' => [$item], 'payment_method' => $method, 'paid_amount' => $paid]);
            $this->assertSame(422, $response->status(), $label . ': ' . $response->getContent());
        }

        $this->catFood->update(['is_active' => false]);
        $this->postSale(['items' => [['product_unit_id' => $this->pack->id, 'qty' => 1]], 'payment_method' => 'qris'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Makanan Kucing 1 kg sedang tidak dijual.');

        $this->sack->update(['is_sellable' => false]);
        $this->postSale(['items' => [['product_unit_id' => $this->sack->id, 'qty' => 1]], 'payment_method' => 'qris'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSAKSI_TIDAK_VALID');

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_warehouse_staff_cannot_sell(): void
    {
        $this->loginAs('gudang');

        $this->postSale(['items' => [['product_unit_id' => $this->pack->id, 'qty' => 1]], 'payment_method' => 'qris'])
            ->assertForbidden()
            ->assertJsonPath('code', 'AKSES_DITOLAK');
    }

    public function test_cashier_sees_only_own_sales_and_owner_sees_all(): void
    {
        $first = $this->loginAs('kasir');
        $mine = $this->postSale(['items' => [['product_unit_id' => $this->pack->id, 'qty' => 1]], 'payment_method' => 'qris'])->json('data.id');

        $this->loginAs('kasir');
        $other = $this->postSale(['items' => [['product_unit_id' => $this->pack->id, 'qty' => 1]], 'payment_method' => 'qris'])->json('data.id');

        Sanctum::actingAs($first);
        $this->getJson('/api/v1/pos/transactions')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine);
        $this->getJson("/api/v1/pos/transactions/{$other}")->assertNotFound();

        $this->loginAs('owner');
        $this->getJson('/api/v1/pos/transactions')->assertOk()->assertJsonPath('total', 2);
        $this->getJson("/api/v1/pos/transactions/{$other}")->assertOk();
    }

    public function test_only_owner_can_cancel_and_stock_returns(): void
    {
        $this->loginAs('kasir');
        $id = $this->postSale([
            'items' => [['product_unit_id' => $this->sack->id, 'qty' => 1]],
            'payment_method' => 'qris',
        ])->json('data.id');

        $this->assertSame('450.000', $this->feed->refresh()->current_stock);

        $this->postJson("/api/v1/pos/transactions/{$id}/cancel", ['reason' => 'salah input'])->assertForbidden();

        $this->loginAs('owner');
        $this->postJson("/api/v1/pos/transactions/{$id}/cancel", ['reason' => 'Salah input'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('500.000', $this->feed->refresh()->current_stock);

        $this->postJson("/api/v1/pos/transactions/{$id}/cancel", ['reason' => 'lagi'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSAKSI_TIDAK_VALID');

        $this->artisan('stock:verify')->assertSuccessful();
    }

    public function test_barcode_lookup_returns_unit_price_and_stock(): void
    {
        $this->loginAs('gudang');

        $this->getJson('/api/v1/barcode/8990000000050')
            ->assertOk()
            ->assertJsonPath('data.product_unit_id', $this->sack->id)
            ->assertJsonPath('data.unit_name', 'Karung')
            ->assertJsonPath('data.base_unit_name', 'Kg')
            ->assertJsonPath('data.price', 420000)
            ->assertJsonPath('data.stock_base_qty', '500.000')
            ->assertJsonCount(2, 'data.price_tiers');

        $this->getJson('/api/v1/barcode/tidak-ada')->assertNotFound()->assertJsonPath('code', 'BARCODE_TIDAK_DITEMUKAN');
    }

    public function test_admin_panel_lists_sales_and_owner_can_cancel(): void
    {
        $this->loginAs('kasir');
        $this->postSale(['items' => [['product_unit_id' => $this->pack->id, 'qty' => 2]], 'payment_method' => 'qris']);
        $transaction = Transaction::query()->firstOrFail();

        $owner = $this->user('owner');
        $this->actingAs($owner);

        Livewire::test(ListTransactions::class)->assertCanSeeTableRecords([$transaction]);

        Livewire::test(ViewTransaction::class, ['record' => $transaction->getRouteKey()])
            ->assertSee('Makanan Kucing 1 kg')
            ->callAction('cancel', data: ['reason' => 'Pelanggan batal'])
            ->assertNotified('Penjualan dibatalkan, stok dikembalikan.');

        $this->assertSame(TransactionStatus::Cancelled, $transaction->refresh()->status);
        $this->assertSame('20.000', $this->catFood->refresh()->current_stock);
    }

    private function postSale(array $body, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) str()->uuid())
            ->postJson('/api/v1/pos/transactions', $body);
    }

    private function loginAs(string $role): User
    {
        $user = $this->user($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function user(string $role, ?string $name = null): User
    {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => $name ?? ucfirst($role) . " {$n}", 'email' => "{$role}{$n}@example.test",
            'password' => 'password', 'role' => $role, 'is_active' => true,
        ]);
    }

    private function unitId(string $code): int
    {
        return Unit::query()->where('code', $code)->value('id');
    }
}
