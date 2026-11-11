<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PurchaseException;
use App\Filament\Resources\PurchaseResource;
use App\Filament\Resources\PurchaseResource\Pages\CreatePurchase;
use App\Filament\Resources\PurchaseResource\Pages\ListPurchases;
use App\Filament\Resources\PurchaseResource\Pages\ViewPurchase;
use App\Filament\Resources\SupplierResource\Pages\EditSupplier;
use App\Filament\Resources\SupplierResource\RelationManagers\PurchasesRelationManager;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\StockService;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $purchases;
    private User $owner;
    private Supplier $supplier;
    private Product $feed;
    private ProductUnit $kg;
    private ProductUnit $sack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchases = app(PurchaseService::class);
        $this->owner = $this->user('owner');
        $this->actingAs($this->owner);

        $this->supplier = Supplier::query()->create(['name' => 'PT Pakan Jaya', 'contact_person' => 'Budi', 'phone' => '0800']);

        $this->feed = Product::query()->create([
            'title' => 'Pakan Ayam Petelur', 'price' => 9000, 'cost_price' => 0, 'weight_gram' => 1000,
        ]);
        $this->kg = $this->feed->units()->create([
            'unit_id' => Unit::query()->where('code', 'KG')->value('id'), 'is_base' => true, 'allow_decimal_qty' => true,
        ]);
        $this->sack = $this->feed->units()->create([
            'unit_id' => Unit::query()->where('code', 'KRG')->value('id'), 'conversion_qty' => 50,
        ]);
    }

    public function test_draft_purchase_gets_reference_number_and_item_snapshot(): void
    {
        $purchase = $this->draft([[$this->sack, 10, 420000]]);

        $this->assertMatchesRegularExpression('/^PB-\d{8}-0001$/', $purchase->reference_no);
        $this->assertSame(PurchaseStatus::Draft, $purchase->status);
        $this->assertSame($this->owner->id, $purchase->created_by);
        $this->assertSame(4200000, $purchase->total_cost);

        $item = $purchase->purchaseItems()->first();
        $this->assertSame('Karung', $item->unit_name);
        $this->assertSame('50.000', $item->conversion_qty);
        $this->assertSame('500.000', $item->base_qty);
        $this->assertSame($this->feed->id, $item->product_id);

        $this->assertStringEndsWith('-0002', $this->draft([[$this->kg, 1, 9000]])->reference_no);
    }

    public function test_receiving_adds_stock_in_base_unit_and_sets_average_cost(): void
    {
        $purchase = $this->draft([
            [$this->sack, 10, 420000], // 500 kg @ 8.400/kg
            [$this->kg, '12.5', 8000], // 12,5 kg @ 8.000/kg
        ]);

        $this->purchases->receive($purchase);

        $this->feed->refresh();
        $this->assertSame('512.500', $this->feed->current_stock);
        // (4.200.000 + 100.000) / 512,5 = 8.390,2 → 8.390
        $this->assertSame(8390, $this->feed->cost_price);

        $purchase->refresh();
        $this->assertSame(PurchaseStatus::Received, $purchase->status);
        $this->assertNotNull($purchase->received_at);
        $this->assertSame($this->owner->id, $purchase->received_by);

        $movements = $purchase->inventoryMovements()->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame(MovementType::Purchase, $movements[0]->movement_type);
        $this->assertSame('Karung', $movements[0]->unit_name);
        $this->assertSame('500.000', $movements[0]->qty_change);
    }

    public function test_average_cost_blends_existing_stock(): void
    {
        $this->feed->update(['cost_price' => 8000]);
        app(StockService::class)->increase($this->feed, 100, MovementType::Purchase);

        $this->purchases->receive($this->draft([[$this->sack, 2, 450000]])); // 100 kg @ 9.000

        // (100 × 8.000 + 900.000) / 200 = 8.500
        $this->assertSame(8500, $this->feed->refresh()->cost_price);
    }

    public function test_purchase_cannot_be_received_twice(): void
    {
        $purchase = $this->draft([[$this->sack, 1, 420000]]);
        $this->purchases->receive($purchase);

        $this->expectException(PurchaseException::class);
        $this->purchases->receive($purchase);
    }

    public function test_purchase_without_items_cannot_be_received(): void
    {
        $purchase = Purchase::query()->create(['supplier_id' => $this->supplier->id, 'purchase_date' => now()]);

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('Pembelian belum punya item.');
        $this->purchases->receive($purchase);
    }

    public function test_receiving_is_all_or_nothing(): void
    {
        $purchase = $this->draft([[$this->sack, 2, 420000], [$this->kg, 5, 8000]]);
        // Simulasikan data rusak: satuan karung tidak boleh pecahan.
        $purchase->purchaseItems()->first()->forceFill(['qty' => '1.5'])->saveQuietly();

        try {
            $this->purchases->receive($purchase);
            $this->fail('Seharusnya gagal.');
        } catch (\App\Exceptions\InvalidStockQuantityException) {
        }

        $this->assertSame('0.000', $this->feed->refresh()->current_stock);
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(PurchaseStatus::Draft, $purchase->refresh()->status);
    }

    public function test_items_are_locked_after_receiving(): void
    {
        $purchase = $this->draft([[$this->sack, 1, 420000]]);
        $this->purchases->receive($purchase);

        $this->expectException(LogicException::class);
        $purchase->purchaseItems()->first()->update(['qty' => 5]);
    }

    public function test_draft_can_be_cancelled_without_stock_change(): void
    {
        $purchase = $this->draft([[$this->sack, 1, 420000]]);

        $this->purchases->cancel($purchase, 'Salah input pemasok', $this->user('admin'));

        $purchase->refresh();
        $this->assertSame(PurchaseStatus::Cancelled, $purchase->status);
        $this->assertSame('Salah input pemasok', $purchase->cancel_reason);
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_only_owner_can_cancel_received_purchase_and_stock_is_reversed(): void
    {
        $purchase = $this->draft([[$this->sack, 2, 420000]]);
        $this->purchases->receive($purchase);

        try {
            $this->purchases->cancel($purchase, 'Barang dikembalikan', $this->user('admin'));
            $this->fail('Admin seharusnya tidak bisa.');
        } catch (PurchaseException) {
        }
        $this->assertSame('100.000', $this->feed->refresh()->current_stock);

        $this->purchases->cancel($purchase, 'Barang dikembalikan', $this->owner);

        $this->assertSame('0.000', $this->feed->refresh()->current_stock);
        $this->assertSame(PurchaseStatus::Cancelled, $purchase->refresh()->status);
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', MovementType::PurchaseCancel)->count());
    }

    public function test_received_purchase_cannot_be_cancelled_if_goods_were_sold(): void
    {
        $purchase = $this->draft([[$this->sack, 1, 420000]]);
        $this->purchases->receive($purchase);
        app(StockService::class)->decrease($this->feed, 10, MovementType::Sale);

        try {
            $this->purchases->cancel($purchase, 'Retur', $this->owner);
            $this->fail('Seharusnya stok tidak cukup.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(PurchaseStatus::Received, $purchase->refresh()->status);
        $this->assertSame('40.000', $this->feed->refresh()->current_stock);
    }

    public function test_admin_can_create_and_receive_purchase_from_panel(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'purchase_date' => now()->toDateString(),
                'supplier_invoice_no' => 'INV-77',
                'purchaseItems' => [
                    ['product_id' => $this->feed->id, 'product_unit_id' => $this->sack->id, 'qty' => 4, 'buy_price' => 420000],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $purchase = Purchase::query()->firstOrFail();
        $this->assertSame(1680000, $purchase->total_cost);
        $this->assertSame('INV-77', $purchase->supplier_invoice_no);

        Livewire::test(ViewPurchase::class, ['record' => $purchase->getRouteKey()])
            ->assertSee('Pakan Ayam Petelur')
            ->callAction('receive')
            ->assertNotified('Barang diterima, stok sudah bertambah.');

        $this->assertSame('200.000', $this->feed->refresh()->current_stock);
        $this->assertSame(PurchaseStatus::Received, $purchase->refresh()->status);

        Livewire::test(ListPurchases::class)
            ->assertCanSeeTableRecords([$purchase])
            ->assertTableActionHidden('receive', $purchase)
            ->assertTableActionHidden('edit', $purchase);

        $this->get(PurchaseResource::getUrl('edit', ['record' => $purchase]))->assertForbidden();
    }

    public function test_fractional_sack_quantity_is_rejected_in_form(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'purchase_date' => now()->toDateString(),
                'purchaseItems' => [
                    ['product_id' => $this->feed->id, 'product_unit_id' => $this->sack->id, 'qty' => '1.5', 'buy_price' => 420000],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['purchaseItems.0.qty']);

        $undoRepeaterFake();

        $this->assertSame(0, Purchase::query()->count());
    }

    public function test_supplier_page_shows_purchase_history(): void
    {
        $purchase = $this->draft([[$this->sack, 1, 420000]]);

        Livewire::test(PurchasesRelationManager::class, [
            'ownerRecord' => $this->supplier,
            'pageClass' => EditSupplier::class,
        ])->assertCanSeeTableRecords([$purchase]);
    }

    /** @param array<int, array{0: ProductUnit, 1: int|string, 2: int}> $lines */
    private function draft(array $lines): Purchase
    {
        $purchase = Purchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now(),
        ]);

        foreach ($lines as [$unit, $qty, $price]) {
            $purchase->purchaseItems()->create(['product_unit_id' => $unit->id, 'qty' => $qty, 'buy_price' => $price]);
        }

        $purchase->recalculateTotal();

        return $purchase->refresh();
    }

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => ucfirst($role), 'email' => "{$role}{$n}@example.test", 'password' => 'password',
            'role' => $role, 'is_active' => true,
        ]);
    }
}
