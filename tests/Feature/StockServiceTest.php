<?php

namespace Tests\Feature;

use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStockQuantityException;
use App\Filament\Resources\InventoryMovementResource\Pages\ListInventoryMovements;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;
    private User $owner;
    private Product $feed;     // pakan curah, dasar Kg
    private ProductUnit $kg;
    private ProductUnit $sack; // Karung = 50 Kg

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->owner = User::query()->create([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password',
            'role' => 'owner', 'is_active' => true,
        ]);
        $this->actingAs($this->owner);

        $this->feed = Product::query()->create([
            'title' => 'Pakan Ayam Petelur', 'price' => 9000, 'cost_price' => 7500, 'weight_gram' => 1000,
        ]);
        $this->kg = $this->feed->units()->create([
            'unit_id' => Unit::query()->where('code', 'KG')->value('id'),
            'is_base' => true, 'allow_decimal_qty' => true,
        ]);
        $this->sack = $this->feed->units()->create([
            'unit_id' => Unit::query()->where('code', 'KRG')->value('id'),
            'conversion_qty' => 50, 'allow_decimal_qty' => false,
        ]);
    }

    public function test_purchase_of_sacks_increases_stock_in_kg_and_records_snapshot(): void
    {
        $supplier = Supplier::query()->create(['name' => 'PT Pakan', 'contact_person' => 'Budi', 'phone' => '0800']);
        $purchase = Purchase::query()->create([
            'supplier_id' => $supplier->id, 'reference_no' => 'PB-001',
            'purchase_date' => now(), 'total_cost' => 0,
        ]);

        $movement = $this->stock->increase($this->feed, 3, MovementType::Purchase, $this->sack, $purchase);

        $this->assertSame('150.000', $this->feed->refresh()->current_stock);
        $this->assertSame(MovementType::Purchase, $movement->movement_type);
        $this->assertSame('Karung', $movement->unit_name);
        $this->assertSame('3.000', $movement->qty);
        $this->assertSame('50.000', $movement->conversion_qty);
        $this->assertSame('150.000', $movement->qty_change);
        $this->assertSame('0.000', $movement->stock_before);
        $this->assertSame('150.000', $movement->stock_after);
        $this->assertSame('purchase', $movement->reference_type);
        $this->assertTrue($movement->reference->is($purchase));
        $this->assertSame($this->owner->id, $movement->user_id);
    }

    public function test_selling_half_a_kilo_reduces_stock_by_decimal_amount(): void
    {
        $this->stock->increase($this->feed, 1, MovementType::Purchase, $this->sack);

        $this->stock->decrease($this->feed, '0.5', MovementType::Sale, $this->kg);
        $movement = $this->stock->decrease($this->feed, '0.25', MovementType::Sale);

        $this->assertSame('49.250', $this->feed->refresh()->current_stock);
        $this->assertSame('-0.250', $movement->qty_change);
        $this->assertSame('Kg', $movement->unit_name);
    }

    public function test_selling_more_than_available_throws_and_changes_nothing(): void
    {
        $this->stock->increase($this->feed, 30, MovementType::Purchase);

        try {
            $this->stock->decrease($this->feed, 1, MovementType::Sale, $this->sack);
            $this->fail('Seharusnya stok tidak cukup.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame('STOK_TIDAK_CUKUP', $exception->errorCode());
            $this->assertSame('30.000', $exception->available);
            $this->assertSame('50.000', $exception->requested);
            $this->assertStringContainsString('Tersedia 30 Kg, diminta 50 Kg', $exception->getMessage());
        }

        $this->assertSame('30.000', $this->feed->refresh()->current_stock);
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_negative_stock_only_when_explicitly_allowed(): void
    {
        $this->stock->decrease($this->feed, 2, MovementType::Sale, allowNegative: true);

        $this->assertSame('-2.000', $this->feed->refresh()->current_stock);
    }

    public function test_fractional_quantity_is_rejected_for_whole_units(): void
    {
        $this->expectException(InvalidStockQuantityException::class);
        $this->expectExceptionMessage('Satuan Karung tidak boleh pecahan');

        $this->stock->increase($this->feed, '0.5', MovementType::Purchase, $this->sack);
    }

    public function test_whole_number_written_as_decimal_is_accepted_for_whole_units(): void
    {
        $this->stock->increase($this->feed, '2.000', MovementType::Purchase, $this->sack);

        $this->assertSame('100.000', $this->feed->refresh()->current_stock);
    }

    public function test_zero_or_negative_quantity_is_rejected(): void
    {
        $this->expectException(InvalidStockQuantityException::class);

        $this->stock->increase($this->feed, 0, MovementType::Purchase);
    }

    public function test_unit_of_another_product_is_rejected(): void
    {
        $other = Product::query()->create(['title' => 'Lain', 'price' => 1, 'cost_price' => 1, 'weight_gram' => 1]);
        $other->units()->create(['unit_id' => Unit::query()->where('code', 'PCS')->value('id'), 'is_base' => true]);

        $this->expectException(InvalidStockQuantityException::class);
        $this->expectExceptionMessage('Satuan tidak terdaftar untuk produk ini.');

        $this->stock->increase($other, 1, MovementType::Purchase, $this->sack);
    }

    public function test_adjustment_records_reason_and_signed_change(): void
    {
        $this->stock->increase($this->feed, 1, MovementType::Purchase, $this->sack);

        $movement = $this->stock->adjust($this->feed, '-1.5', AdjustmentReason::Shrinkage, notes: 'Tercecer saat bongkar');

        $this->assertSame('48.500', $this->feed->refresh()->current_stock);
        $this->assertSame(MovementType::Adjustment, $movement->movement_type);
        $this->assertSame('susut', $movement->reason);
        $this->assertSame('-1.500', $movement->qty_change);
        $this->assertSame('1.500', $movement->qty);
    }

    public function test_adjustment_with_reason_other_requires_notes(): void
    {
        $this->expectException(InvalidStockQuantityException::class);

        $this->stock->adjust($this->feed, 5, AdjustmentReason::Other);
    }

    public function test_opname_sets_counted_stock_and_records_difference(): void
    {
        $this->stock->increase($this->feed, 2, MovementType::Purchase, $this->sack);

        $movement = $this->stock->opname($this->feed, '97.5', 'Hitung akhir bulan');

        $this->assertSame('97.500', $this->feed->refresh()->current_stock);
        $this->assertSame(MovementType::Opname, $movement->movement_type);
        $this->assertSame('-2.500', $movement->qty_change);
        $this->assertSame('100.000', $movement->stock_before);

        $same = $this->stock->opname($this->feed, '97.5');
        $this->assertSame('0.000', $same->qty_change);
    }

    public function test_movements_cannot_be_updated_or_deleted(): void
    {
        $movement = $this->stock->increase($this->feed, 1, MovementType::Purchase);

        try {
            $movement->update(['notes' => 'ubah']);
            $this->fail('Mutasi seharusnya tidak bisa diubah.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_base_unit_is_locked_once_stock_history_exists(): void
    {
        $this->assertFalse($this->feed->hasStockHistory());

        $this->stock->increase($this->feed, 1, MovementType::Purchase);

        $this->assertTrue($this->feed->hasStockHistory());
    }

    public function test_insufficient_stock_renders_api_error_shape(): void
    {
        Route::middleware('api')->post('/api/test-sale', function () {
            app(StockService::class)->decrease(Product::query()->firstOrFail(), 1, MovementType::Sale);
        });

        $this->postJson('/api/test-sale')
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOK_TIDAK_CUKUP')
            ->assertJsonPath('details.product_id', $this->feed->id)
            ->assertJsonPath('details.available', '0.000')
            ->assertJsonPath('details.requested', '1.000');
    }

    public function test_stock_history_page_lists_movements(): void
    {
        $this->stock->increase($this->feed, 2, MovementType::Purchase, $this->sack);
        $this->stock->adjust($this->feed, '-0.5', AdjustmentReason::Shrinkage);

        Livewire::test(ListInventoryMovements::class)
            ->assertCanSeeTableRecords(InventoryMovement::query()->get())
            ->assertSee('Pakan Ayam Petelur')
            ->assertSee('+100 Kg')
            ->assertSee('-0,5 Kg')
            ->assertSee('Susut / tercecer')
            ->filterTable('movement_type', MovementType::Adjustment->value)
            ->assertCanSeeTableRecords(InventoryMovement::query()->where('movement_type', 'ADJUSTMENT')->get())
            ->assertCanNotSeeTableRecords(InventoryMovement::query()->where('movement_type', 'PURCHASE')->get());
    }

    public function test_admin_can_adjust_stock_from_product_table(): void
    {
        Storage::fake('s3');

        Livewire::test(ListProducts::class)
            ->callTableAction('adjustStock', $this->feed, data: [
                'direction' => 'in',
                'product_unit_id' => $this->sack->id,
                'qty' => 2,
                'reason' => AdjustmentReason::InitialStock->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('100.000', $this->feed->refresh()->current_stock);

        Livewire::test(ListProducts::class)
            ->callTableAction('adjustStock', $this->feed, data: [
                'direction' => 'out',
                'product_unit_id' => $this->sack->id,
                'qty' => 3,
                'reason' => AdjustmentReason::Damaged->value,
            ])
            ->assertNotified('Stok tidak diubah');

        $this->assertSame('100.000', $this->feed->refresh()->current_stock);

        Livewire::test(ListProducts::class)
            ->callTableAction('opnameStock', $this->feed, data: ['counted' => '98.75'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('98.750', $this->feed->refresh()->current_stock);
        $this->assertSame(2, InventoryMovement::query()->count());
    }
}
