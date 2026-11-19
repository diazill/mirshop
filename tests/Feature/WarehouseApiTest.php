<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Device;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fitur Kelola barang aplikasi HP: barcode, opname/koreksi stok, foto produk. */
class WarehouseApiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private Product $feed;
    private ProductUnit $kg;
    private ProductUnit $sack;
    private Product $catFood;
    private ProductUnit $pack;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->feed = Product::query()->create(['title' => 'Pakan Ayam', 'price' => 9000, 'cost_price' => 8000, 'weight_gram' => 1000]);
        $this->kg = $this->feed->units()->create(['unit_id' => $this->unitId('KG'), 'is_base' => true, 'allow_decimal_qty' => true]);
        $this->sack = $this->feed->units()->create(['unit_id' => $this->unitId('KRG'), 'conversion_qty' => 50]);

        $this->catFood = Product::query()->create(['title' => 'Makanan Kucing', 'price' => 25000, 'cost_price' => 20000, 'weight_gram' => 1000]);
        $this->pack = $this->catFood->units()->create(['unit_id' => $this->unitId('BKS'), 'is_base' => true, 'barcode' => '8991001000011']);

        $this->staff = $this->as('gudang');
    }

    // ---- Barcode ----

    public function test_staff_attaches_barcode_and_audit_is_recorded(): void
    {
        $this->putJson("/api/v1/product-units/{$this->sack->id}/barcode", ['barcode' => ' 8991002000050 '])
            ->assertOk()
            ->assertJsonPath('data.product_unit_id', $this->sack->id)
            ->assertJsonPath('data.barcode', '8991002000050')
            ->assertJsonPath('data.unit_name', 'Karung')
            ->assertJsonPath('data.base_unit_name', 'Kg');

        $this->sack->refresh();
        $this->assertSame('8991002000050', $this->sack->barcode);
        $this->assertSame($this->staff->id, $this->sack->barcode_updated_by);
        $this->assertNotNull($this->sack->barcode_updated_at);

        // Bentuk data sama dengan scan barcode.
        $scan = $this->getJson('/api/v1/barcode/8991002000050')->assertOk()->json('data');
        $this->assertSame($scan, $this->putJson("/api/v1/product-units/{$this->sack->id}/barcode", ['barcode' => '8991002000050'])->json('data'));
    }

    public function test_barcode_used_by_another_unit_returns_its_owner(): void
    {
        $this->putJson("/api/v1/product-units/{$this->sack->id}/barcode", ['barcode' => '8991001000011'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BARCODE_SUDAH_DIPAKAI')
            ->assertJsonPath('message', 'Barcode 8991001000011 sudah dipakai Makanan Kucing (Bungkus).')
            ->assertJsonPath('details.product_id', $this->catFood->id)
            ->assertJsonPath('details.product_unit_id', $this->pack->id)
            ->assertJsonPath('details.title', 'Makanan Kucing')
            ->assertJsonPath('details.unit_name', 'Bungkus');

        $this->assertNull($this->sack->refresh()->barcode);
    }

    public function test_same_barcode_on_itself_is_ok_and_null_detaches(): void
    {
        $this->putJson("/api/v1/product-units/{$this->pack->id}/barcode", ['barcode' => '8991001000011'])->assertOk();

        $this->putJson("/api/v1/product-units/{$this->pack->id}/barcode", ['barcode' => null])
            ->assertOk()
            ->assertJsonPath('data.barcode', null);

        $this->assertNull($this->pack->refresh()->barcode);
        $this->getJson('/api/v1/barcode/8991001000011')->assertNotFound();
    }

    public function test_barcode_format_and_deleted_product_are_rejected(): void
    {
        foreach (['123', 'abc def', str_repeat('9', 65), 'kode/1'] as $bad) {
            $this->putJson("/api/v1/product-units/{$this->sack->id}/barcode", ['barcode' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDASI_GAGAL');
        }

        $this->putJson("/api/v1/product-units/{$this->sack->id}/barcode", [])->assertStatus(422);

        $this->feed->delete();
        $this->putJson("/api/v1/product-units/{$this->sack->id}/barcode", ['barcode' => '8991002000050'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUK_DIHAPUS');
    }

    public function test_cashier_and_guest_cannot_use_warehouse_endpoints(): void
    {
        $this->as('kasir');
        $this->putJson("/api/v1/product-units/{$this->sack->id}/barcode", ['barcode' => '8991002000050'])->assertForbidden();
        $this->opname(['product_id' => $this->feed->id, 'counted_base_qty' => '1', 'reason' => 'stok_awal'])->assertForbidden();
        $this->post("/api/v1/products/{$this->feed->id}/images", ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertForbidden();

    }

    public function test_guest_without_token_is_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->putJson("/api/v1/product-units/{$this->sack->id}/barcode", ['barcode' => '8991002000050'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TIDAK_TERAUTENTIKASI');
    }

    // ---- Opname ----

    public function test_initial_stock_is_recorded_as_opname_with_reason_and_user(): void
    {
        $this->opname([
            'product_id' => $this->catFood->id, 'counted_base_qty' => '37', 'expected_base_qty' => '0',
            'reason' => 'stok_awal', 'notes' => 'Hitung rak depan',
        ])->assertOk()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('data.current_stock', '37.000')
            ->assertJsonPath('data.base_unit_name', 'Bungkus')
            ->assertJsonPath('data.movement.type', 'OPNAME')
            ->assertJsonPath('data.movement.reason', 'stok_awal')
            ->assertJsonPath('data.movement.qty_change', '37.000')
            ->assertJsonPath('data.movement.stock_after', '37.000');

        $movement = InventoryMovement::query()->latest('id')->firstOrFail();
        $this->assertSame($this->staff->id, $movement->user_id);
        $this->assertSame('Hitung rak depan', $movement->notes);
    }

    public function test_opname_keeps_sales_made_while_counting(): void
    {
        $stock = app(StockService::class);
        $stock->increase($this->catFood, 40, MovementType::Purchase);
        $stock->decrease($this->catFood, 3, MovementType::Sale); // stok sekarang 37

        // Staf melihat 40 saat mulai hitung, menghitung 39 (1 hilang). Penjualan 3 tetap tercatat.
        $this->opname(['product_id' => $this->catFood->id, 'counted_base_qty' => '39', 'expected_base_qty' => '40', 'reason' => 'salah_hitung'])
            ->assertOk()
            ->assertJsonPath('data.current_stock', '36.000')
            ->assertJsonPath('data.movement.qty_change', '-1.000');
    }

    public function test_resending_same_key_replays_without_changing_stock_twice(): void
    {
        $body = ['product_id' => $this->catFood->id, 'counted_base_qty' => '37', 'expected_base_qty' => '0', 'reason' => 'stok_awal'];

        $first = $this->opname($body, 'kunci-1')->assertOk()->assertJsonPath('replayed', false);
        $again = $this->opname(['counted_base_qty' => '37.000'] + $body, 'kunci-1')->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame($first->json('data.movement.id'), $again->json('data.movement.id'));
        $this->assertSame('37.000', $this->catFood->refresh()->current_stock);
        $this->assertSame(1, InventoryMovement::query()->count());

        $this->opname(['counted_base_qty' => '50'] + $body, 'kunci-1')
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_BENTROK');
    }

    public function test_opname_requires_idempotency_key_and_valid_quantities(): void
    {
        $this->postJson('/api/v1/stock/opname', ['product_id' => $this->catFood->id, 'counted_base_qty' => '1', 'reason' => 'stok_awal'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_WAJIB');

        // Satuan dasar Bungkus tidak boleh pecahan.
        $this->opname(['product_id' => $this->catFood->id, 'counted_base_qty' => '2.5', 'reason' => 'stok_awal'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'JUMLAH_TIDAK_VALID');

        $this->opname(['product_id' => $this->catFood->id, 'counted_base_qty' => '-1', 'reason' => 'stok_awal'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDASI_GAGAL');

        $this->opname(['product_id' => $this->catFood->id, 'counted_base_qty' => '1', 'reason' => 'lainnya'])
            ->assertStatus(422)->assertJsonPath('errors.notes.0', 'Catatan wajib diisi untuk alasan "Lainnya".');

        $this->opname(['product_id' => 999999, 'counted_base_qty' => '1', 'reason' => 'stok_awal'])
            ->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');

        $this->assertSame(0, InventoryMovement::query()->count());
    }

    // ---- Adjust ----

    public function test_adjust_adds_and_removes_in_non_base_unit(): void
    {
        $this->adjust(['product_id' => $this->feed->id, 'product_unit_id' => $this->sack->id, 'qty' => '3', 'reason' => 'stok_awal'])
            ->assertOk()
            ->assertJsonPath('data.current_stock', '150.000')
            ->assertJsonPath('data.movement.type', 'ADJUSTMENT')
            ->assertJsonPath('data.movement.unit_name', 'Karung')
            ->assertJsonPath('data.movement.qty_change', '150.000');

        $this->adjust(['product_id' => $this->feed->id, 'product_unit_id' => $this->sack->id, 'qty' => '-1', 'reason' => 'rusak'])
            ->assertOk()
            ->assertJsonPath('data.current_stock', '100.000')
            ->assertJsonPath('data.movement.reason', 'rusak');

        // Tanpa product_unit_id: satuan dasar (Kg).
        $this->adjust(['product_id' => $this->feed->id, 'qty' => '-2.5', 'reason' => 'susut'])
            ->assertOk()
            ->assertJsonPath('data.current_stock', '97.500');
    }

    public function test_adjust_cannot_make_stock_negative_and_requires_notes_for_other(): void
    {
        $this->adjust(['product_id' => $this->feed->id, 'product_unit_id' => $this->sack->id, 'qty' => '-1', 'reason' => 'rusak'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOK_TIDAK_CUKUP');

        $this->adjust(['product_id' => $this->feed->id, 'qty' => '5', 'reason' => 'lainnya'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDASI_GAGAL');

        $this->adjust(['product_id' => $this->feed->id, 'product_unit_id' => $this->pack->id, 'qty' => '1', 'reason' => 'stok_awal'])
            ->assertNotFound();

        $this->adjust(['product_id' => $this->feed->id, 'product_unit_id' => $this->sack->id, 'qty' => '0.5', 'reason' => 'stok_awal'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'JUMLAH_TIDAK_VALID');

        $this->assertSame('0.000', $this->feed->refresh()->current_stock);
    }

    public function test_adjust_replays_with_same_key(): void
    {
        $body = ['product_id' => $this->feed->id, 'qty' => '10', 'reason' => 'stok_awal'];

        $this->adjust($body, 'k-adj')->assertJsonPath('replayed', false);
        $this->adjust($body, 'k-adj')->assertJsonPath('replayed', true);

        $this->assertSame('10.000', $this->feed->refresh()->current_stock);
    }

    // ---- Foto ----

    public function test_first_photo_becomes_primary_and_is_stored_like_admin_uploads(): void
    {
        $before = $this->catFood->updated_at;
        $this->travel(1)->minutes();

        $response = $this->upload($this->catFood, UploadedFile::fake()->image('foto.jpg', 1200, 1200))
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonPath('product.image_count', 1);

        $image = ProductImage::query()->firstOrFail();
        $this->assertMatchesRegularExpression('#^katalog-produk/' . now()->format('Y/m') . '/[0-9a-f-]{36}\.jpg$#', $image->image_url);
        Storage::disk('s3')->assertExists($image->image_url);
        $this->assertSame($response->json('data.url'), $response->json('product.image_url'));
        $this->assertTrue($this->catFood->refresh()->updated_at->isAfter($before));
    }

    public function test_marking_new_photo_primary_unsets_the_previous_one(): void
    {
        $this->upload($this->catFood, UploadedFile::fake()->image('a.jpg'))->assertCreated();
        $this->upload($this->catFood, UploadedFile::fake()->image('b.png'))->assertCreated()->assertJsonPath('data.is_primary', false);
        $third = $this->upload($this->catFood, UploadedFile::fake()->image('c.webp'), ['is_primary' => '1'])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.sort_order', 3)
            ->assertJsonPath('product.image_count', 3);

        $this->assertSame([$third->json('data.id')], ProductImage::query()->where('is_primary', true)->pluck('id')->all());
    }

    public function test_non_images_and_large_files_are_rejected(): void
    {
        $this->upload($this->catFood, UploadedFile::fake()->create('dok.pdf', 100, 'application/pdf'))
            ->assertStatus(422)->assertJsonPath('code', 'VALIDASI_GAGAL');

        $this->upload($this->catFood, UploadedFile::fake()->image('besar.jpg')->size(6000))
            ->assertStatus(422)->assertJsonPath('errors.image.0', 'Ukuran foto maksimal 5 MB.');

        $this->assertSame(0, ProductImage::query()->count());
    }

    // ---- Katalog ----

    public function test_catalog_reports_photo_state_and_new_photos_appear_in_delta(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner', 'email' => 'owner-sync@example.test', 'password' => 'password', 'role' => 'owner', 'is_active' => true,
        ]);
        $device = Device::query()->create(['code' => 'G01', 'name' => 'HP gudang']);
        $token = $owner->createToken('hp');
        $token->accessToken->forceFill(['device_id' => $device->id])->save();

        $catalog = fn (?string $since = null) => $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/sync/catalog' . ($since ? '?since=' . urlencode($since) : ''));

        // Lewati jendela tumpang-tindih 10 detik dari server_time agar delta hanya berisi perubahan baru.
        $this->travel(1)->minutes();
        $this->app['auth']->forgetGuards();
        $full = $catalog()->assertOk();
        $cat = collect($full->json('products'))->firstWhere('id', $this->catFood->id);
        $this->assertNull($cat['image_url']);
        $this->assertSame(0, $cat['image_count']);

        $since = $full->json('server_time');
        $this->travel(1)->minutes();

        Sanctum::actingAs($this->staff);
        $this->upload($this->catFood, UploadedFile::fake()->image('baru.jpg'))->assertCreated();

        $this->app['auth']->forgetGuards();
        $delta = collect($catalog($since)->assertOk()->json('products'));
        $this->assertSame([$this->catFood->id], $delta->pluck('id')->all());
        $this->assertSame(1, $delta->first()['image_count']);
        $this->assertNotNull($delta->first()['image_url']);
    }

    private function opname(array $body, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) str()->uuid())->postJson('/api/v1/stock/opname', $body);
    }

    private function adjust(array $body, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) str()->uuid())->postJson('/api/v1/stock/adjust', $body);
    }

    private function upload(Product $product, UploadedFile $file, array $extra = [])
    {
        return $this->post("/api/v1/products/{$product->id}/images", ['image' => $file] + $extra, ['Accept' => 'application/json']);
    }

    private function as(string $role): User
    {
        static $n = 0;
        $n++;

        $user = User::query()->create([
            'name' => ucfirst($role) . " {$n}", 'email' => "{$role}{$n}@example.test",
            'password' => 'password', 'role' => $role, 'is_active' => true,
        ]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    private function unitId(string $code): int
    {
        return Unit::query()->where('code', $code)->value('id');
    }
}
