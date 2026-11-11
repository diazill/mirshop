<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->actingAs(User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
        ]));
    }

    public function test_default_units_are_seeded(): void
    {
        $this->assertTrue(Unit::query()->where('code', 'KG')->value('allow_decimal_qty'));
        $this->assertFalse(Unit::query()->where('code', 'KRG')->value('allow_decimal_qty'));
    }

    public function test_admin_can_create_bulk_feed_with_kg_base_and_sack_unit(): void
    {
        $kg = Unit::query()->where('code', 'KG')->value('id');
        $karung = Unit::query()->where('code', 'KRG')->value('id');

        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->productData([
                'units' => [
                    $this->unitRow($kg, isBase: true, allowDecimal: true),
                    $this->unitRow($karung, conversion: 50, barcode: '899000000050', prices: [
                        ['price_type' => 'eceran', 'min_qty' => 1, 'price' => 420000],
                    ]),
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $product = Product::query()->where('title', 'Pakan Ayam Petelur')->firstOrFail();

        $this->assertSame('Kg', $product->baseUnit->unit->name);
        $this->assertTrue($product->baseUnit->allow_decimal_qty);

        $sack = $product->units()->where('unit_id', $karung)->firstOrFail();
        $this->assertSame('50.000', $sack->conversion_qty);
        $this->assertSame('899000000050', $sack->barcode);
        $this->assertSame(420000, $sack->prices()->firstOrFail()->price);
        $this->assertSame('100.000', $sack->toBaseQty(2));
    }

    public function test_product_must_have_exactly_one_base_unit(): void
    {
        $kg = Unit::query()->where('code', 'KG')->value('id');
        $karung = Unit::query()->where('code', 'KRG')->value('id');

        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->productData([
                'units' => [
                    $this->unitRow($kg),
                    $this->unitRow($karung, conversion: 50),
                ],
            ]))
            ->call('create')
            ->assertHasFormErrors(['units']);

        Livewire::test(CreateProduct::class)
            ->fillForm($this->productData([
                'units' => [
                    $this->unitRow($kg, isBase: true),
                    $this->unitRow($karung, isBase: true),
                ],
            ]))
            ->call('create')
            ->assertHasFormErrors(['units']);

        $undoRepeaterFake();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_base_unit_conversion_is_always_one(): void
    {
        $product = Product::query()->create([
            'title' => 'Whiskas 1,2 kg',
            'price' => 65000,
            'cost_price' => 55000,
            'weight_gram' => 1200,
        ]);

        $unit = ProductUnit::query()->create([
            'product_id' => $product->id,
            'unit_id' => Unit::query()->where('code', 'BKS')->value('id'),
            'conversion_qty' => 12,
            'is_base' => true,
            'barcode' => '',
        ]);

        $this->assertSame('1.000', $unit->fresh()->conversion_qty);
        $this->assertNull($unit->fresh()->barcode);
    }

    public function test_quantities_are_formatted_in_indonesian_style(): void
    {
        $this->assertSame('237,5', Product::formatQty('237.500'));
        $this->assertSame('1.500', Product::formatQty(1500));
        $this->assertSame('0,25', Product::formatQty('0.250'));
        $this->assertSame('0', Product::formatQty(0));
    }

    public function test_low_stock_uses_min_stock(): void
    {
        $product = new Product(['current_stock' => '20.000', 'min_stock' => '25.000']);
        $this->assertTrue($product->isLowStock());

        $product->min_stock = null;
        $this->assertFalse($product->isLowStock());
    }

    private function productData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Pakan Ayam Petelur',
            'price' => 9000,
            'cost_price' => 7500,
            'weight_gram' => 1000,
            'is_best_product' => false,
            'is_active' => true,
            'images' => [],
        ], $overrides);
    }

    private function unitRow(
        int $unitId,
        bool $isBase = false,
        float $conversion = 1,
        bool $allowDecimal = false,
        ?string $barcode = null,
        array $prices = [],
    ): array {
        return [
            'unit_id' => $unitId,
            'is_base' => $isBase,
            'conversion_qty' => $conversion,
            'barcode' => $barcode,
            'allow_decimal_qty' => $allowDecimal,
            'is_sellable' => true,
            'is_purchasable' => true,
            'prices' => $prices,
        ];
    }
}
