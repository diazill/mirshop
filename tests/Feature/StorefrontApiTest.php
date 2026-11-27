<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Filament\Pages\StoreSettings;
use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSlug;
use App\Models\StoreClosure;
use App\Models\StoreSchedule;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use App\Support\StoreProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** API katalog publik tahap 2 untuk toko online (Next.js). */
class StorefrontApiTest extends TestCase
{
    use RefreshDatabase;

    // --- Slug produk ---------------------------------------------------------------------------

    public function test_slugs_are_generated_once_and_old_links_keep_working(): void
    {
        $first = $this->product('Pakan Ayam Jago 1 kg');
        $first->units()->create(['unit_id' => Unit::query()->where('code', 'BKS')->value('id'), 'is_base' => true]);
        $twin = $this->product('Pakan Ayam Jago 1 kg');
        $numeric = $this->product('123');

        $this->assertSame('pakan-ayam-jago-1-kg', $first->slug);
        $this->assertSame('pakan-ayam-jago-1-kg-2', $twin->slug);
        $this->assertSame('produk-123', $numeric->slug);

        // Nama berubah atau stok berubah: slug tetap.
        $first->update(['title' => 'Pakan Ayam Jago Super 1 kg']);
        app(StockService::class)->increase($first, 5, MovementType::Purchase);
        $this->assertSame('pakan-ayam-jago-1-kg', $first->refresh()->slug);

        // Slug diganti manual: alamat lama tetap membuka produk, dan tidak bisa diambil produk lain.
        $first->update(['slug' => 'Pakan Jago Super']);
        $this->assertSame('pakan-jago-super', $first->slug);
        $this->assertTrue(ProductSlug::query()->where('slug', 'pakan-ayam-jago-1-kg')->where('product_id', $first->id)->exists());

        foreach (['pakan-jago-super', 'pakan-ayam-jago-1-kg', (string) $first->id] as $key) {
            $this->getJson("/api/v1/products/{$key}")
                ->assertOk()
                ->assertJsonPath('data.id', $first->id)
                ->assertJsonPath('data.slug', 'pakan-jago-super')
                ->assertJsonPath('data.title', 'Pakan Ayam Jago Super 1 kg')
                ->assertJsonPath('data.stock_status', 'tersedia')
                ->assertJsonMissingPath('data.cost_price');
        }

        $this->assertSame('pakan-ayam-jago-1-kg-3', $this->product('Pakan Ayam Jago 1 kg')->slug);

        // Kembali ke slug lama sendiri diperbolehkan.
        $first->update(['slug' => 'pakan-ayam-jago-1-kg']);
        $this->assertSame('pakan-ayam-jago-1-kg', $first->refresh()->slug);
        $this->assertFalse(ProductSlug::query()->where('slug', 'pakan-ayam-jago-1-kg')->exists());
    }

    public function test_inactive_deleted_or_unknown_products_are_not_found(): void
    {
        $inactive = $this->product('Nonaktif', ['is_active' => false]);
        $deleted = $this->product('Terhapus');
        $deleted->delete();

        foreach ([$inactive->slug, $deleted->slug, (string) $deleted->id, 'tidak-ada', '999999'] as $key) {
            $this->getJson("/api/v1/products/{$key}")
                ->assertNotFound()
                ->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        }
    }

    // --- Daftar produk, kategori, merek --------------------------------------------------------

    public function test_product_list_carries_slugs_and_dates_and_filters_by_slug(): void
    {
        Storage::fake('s3');

        $cat = Category::query()->create(['name' => 'Kucing']);
        $bird = Category::query()->create(['name' => 'Burung']);
        $brand = Brand::query()->create(['name' => 'Royal Canin', 'logo' => 'brands/rc.webp']);
        $catFood = $this->product('Royal Canin Kitten', ['category_id' => $cat->id, 'brand_id' => $brand->id]);
        $this->product('Pakan Burung', ['category_id' => $bird->id]);

        $this->getJson('/api/v1/products?category=kucing')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.slug', 'royal-canin-kitten')
            ->assertJsonPath('data.0.category', ['id' => $cat->id, 'name' => 'Kucing', 'slug' => 'kucing'])
            ->assertJsonPath('data.0.brand.slug', 'royal-canin')
            ->assertJsonPath('data.0.brand.logo_url', '/storage/brands/rc.webp')
            ->assertJsonPath('data.0.updated_at', $catFood->updated_at->toIso8601String());

        $this->getJson('/api/v1/products?brand=royal-canin')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/products?category=tidak-ada')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/products?q=50%25')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_categories_and_brands_include_empty_ones_with_active_counts_and_order(): void
    {
        $dog = Category::query()->create(['name' => 'Anjing', 'sort_order' => 2]);
        $cat = Category::query()->create(['name' => 'Kucing', 'sort_order' => 1]);
        $poultry = Category::query()->create(['name' => 'Unggas', 'sort_order' => 3]);
        $bolt = Brand::query()->create(['name' => 'BOLT']);
        $acme = Brand::query()->create(['name' => 'Acme']);

        $this->product('A', ['category_id' => $cat->id, 'brand_id' => $bolt->id]);
        $this->product('B', ['category_id' => $cat->id, 'brand_id' => $bolt->id]);
        $this->product('C', ['category_id' => $cat->id, 'brand_id' => $bolt->id, 'is_active' => false]);
        $this->product('D', ['category_id' => $dog->id]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $cat->id, 'name' => 'Kucing', 'slug' => 'kucing', 'parent_id' => null, 'product_count' => 2, 'product_count_total' => 2, 'sort_order' => 1],
                ['id' => $dog->id, 'name' => 'Anjing', 'slug' => 'anjing', 'parent_id' => null, 'product_count' => 1, 'product_count_total' => 1, 'sort_order' => 2],
                ['id' => $poultry->id, 'name' => 'Unggas', 'slug' => 'unggas', 'parent_id' => null, 'product_count' => 0, 'product_count_total' => 0, 'sort_order' => 3],
            ]]);

        $this->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonPath('data.0', ['id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'logo_url' => null, 'product_count' => 0])
            ->assertJsonPath('data.1.slug', 'bolt')
            ->assertJsonPath('data.1.product_count', 2);
    }

    public function test_batch_lookup_by_ids_or_slugs_for_cart_and_prerender(): void
    {
        $a = $this->product('Pakan A');
        $b = $this->product('Pakan B');
        $inactive = $this->product('Pakan C', ['is_active' => false]);
        $renamed = $this->product('Pakan D');
        $renamed->update(['slug' => 'pakan-d-baru']);

        $ids = fn ($response): array => array_column($response->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids($this->getJson("/api/v1/products?ids={$a->id},{$b->id},{$inactive->id},999")));
        $this->assertEqualsCanonicalizing(
            [$a->id, $renamed->id],
            $ids($this->getJson('/api/v1/products?slugs=pakan-a,pakan-d,pakan-c,tidak-ada')), // pakan-d = slug lama
        );

        $this->getJson('/api/v1/products?ids=1,abc')->assertUnprocessable()->assertJsonPath('code', 'VALIDASI_GAGAL');
        $this->getJson('/api/v1/products?ids=' . implode(',', range(1, 101)))->assertUnprocessable()->assertJsonValidationErrors('ids');
    }

    public function test_parent_category_can_include_products_of_its_subcategories(): void
    {
        $poultry = Category::query()->create(['name' => 'Poultry']);
        $chicken = Category::query()->create(['name' => 'Unggas', 'parent_id' => $poultry->id]);
        $bird = Category::query()->create(['name' => 'Burung', 'parent_id' => $poultry->id]);
        $pigeon = Category::query()->create(['name' => 'Merpati', 'parent_id' => $bird->id]);

        $this->product('Pakan Ayam', ['category_id' => $chicken->id]);
        $this->product('Pakan Burung', ['category_id' => $bird->id]);
        $this->product('Pakan Merpati', ['category_id' => $pigeon->id]);
        $this->product('Nonaktif', ['category_id' => $pigeon->id, 'is_active' => false]);

        $this->getJson('/api/v1/products?category=poultry')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/products?category=poultry&include_children=1')->assertJsonPath('total', 3);
        $this->getJson("/api/v1/products?category_id={$bird->id}&include_children=1")->assertJsonPath('total', 2);

        $counts = collect($this->getJson('/api/v1/categories')->json('data'))->keyBy('slug');
        $this->assertSame([0, 3], [$counts['poultry']['product_count'], $counts['poultry']['product_count_total']]);
        $this->assertSame([1, 2], [$counts['burung']['product_count'], $counts['burung']['product_count_total']]);
    }

    public function test_storefront_server_key_gets_a_higher_rate_limit(): void
    {
        config(['services.storefront.key' => 'rahasia-toko', 'services.storefront.per_minute' => 300]);
        $this->product('Pakan A');

        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/categories')->assertOk();
        }
        $this->getJson('/api/v1/categories')->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERMINTAAN');

        // Kunci salah = pengunjung biasa (ikut batas IP); kunci benar = batas sendiri.
        $this->withHeader('X-Storefront-Key', 'salah')->getJson('/api/v1/categories')->assertStatus(429);
        for ($i = 0; $i < 150; $i++) {
            $this->withHeader('X-Storefront-Key', 'rahasia-toko')->getJson('/api/v1/categories')->assertOk();
        }
    }

    // --- Info toko publik ----------------------------------------------------------------------

    public function test_store_info_is_null_safe_before_the_owner_fills_it(): void
    {
        $this->getJson('/api/v1/store/public')
            ->assertOk()
            ->assertJsonPath('data.name', null) // bukan APP_NAME: toko online memakai nilai cadangannya sendiri
            ->assertJsonPath('data.address.street', null)
            ->assertJsonPath('data.address.country', 'ID')
            ->assertJsonPath('data.whatsapp', null)
            ->assertJsonPath('data.schedule', [])
            ->assertJsonPath('data.closures', [])
            ->assertJsonPath('data.status', null)
            ->assertJsonMissingPath('data.receipt_footer');
    }

    public function test_store_info_schedule_closures_and_open_status(): void
    {
        StoreProfile::save([
            'store_name' => 'Toko Pakan',
            'store_street' => 'Jl. Contoh No. 1',
            'store_city' => 'Kota Contoh',
            'store_whatsapp' => '0812-3456-7890',
            'store_latitude' => '-7.36',
            'store_longitude' => '110.52',
        ]);
        foreach (range(1, 6) as $day) {
            StoreSchedule::query()->create(['day_of_week' => $day, 'open_time' => '09:00', 'close_time' => '17:00', 'is_closed' => false]);
        }
        StoreSchedule::query()->create(['day_of_week' => 7, 'open_time' => '00:00', 'close_time' => '00:00', 'is_closed' => true]);
        StoreClosure::query()->create(['date' => '2026-11-23', 'reason' => 'Libur toko']); // Senin
        StoreClosure::query()->create(['date' => '2027-06-01', 'reason' => 'Terlalu jauh']);

        // Jumat 20 Nov 10.00 WIB: buka sampai 17.00.
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'Asia/Jakarta'));
        $this->getJson('/api/v1/store/public')
            ->assertOk()
            ->assertJsonPath('data.whatsapp', '6281234567890')
            ->assertJsonPath('data.latitude', -7.36)
            ->assertJsonPath('data.address.street', 'Jl. Contoh No. 1')
            ->assertJsonCount(7, 'data.schedule')
            ->assertJsonPath('data.schedule.0', ['day' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00', 'is_closed' => false])
            ->assertJsonPath('data.schedule.6', ['day' => 7, 'opens_at' => null, 'closes_at' => null, 'is_closed' => true])
            ->assertJsonPath('data.closures', [['date' => '2026-11-23', 'reason' => 'Libur toko']])
            ->assertJsonPath('data.status', ['is_open' => true, 'closes_at' => '2026-11-20T17:00:00+07:00', 'next_open_at' => null]);

        // Sabtu 18.00: tutup; Minggu tutup, Senin libur → buka lagi Selasa 09.00.
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-11-21 18:00', 'Asia/Jakarta'));
        $this->getJson('/api/v1/store/public')
            ->assertJsonPath('data.status', ['is_open' => false, 'closes_at' => null, 'next_open_at' => '2026-11-24T09:00:00+07:00']);

        // Selasa 07.30: belum buka → hari ini 09.00.
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-11-24 07:30', 'Asia/Jakarta'));
        $this->getJson('/api/v1/store/public')
            ->assertJsonPath('data.status.next_open_at', '2026-11-24T09:00:00+07:00')
            ->assertJsonPath('data.closures', []);
    }

    // --- Cache ---------------------------------------------------------------------------------

    public function test_public_responses_are_cached_briefly_and_errors_are_not(): void
    {
        // Flag statis Livewire yang terbawa dari tes panel sebelumnya (di produksi tiap request mulai bersih).
        \Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache::$disableBackButtonCache = false;

        $product = $this->product('Pakan Kucing');

        $response = $this->getJson('/api/v1/products');
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=60', $response->headers->get('Cache-Control'));

        // Dalam 60 detik hasil sama dari cache; setelahnya perubahan terlihat.
        $product->update(['title' => 'Pakan Kucing Baru']);
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.title', 'Pakan Kucing');
        $this->travel(61)->seconds();
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.title', 'Pakan Kucing Baru');

        $missing = $this->getJson('/api/v1/products/tidak-ada')->assertNotFound();
        $this->assertStringNotContainsString('public', (string) $missing->headers->get('Cache-Control'));

        $invalid = $this->getJson('/api/v1/products?per_page=999')->assertUnprocessable();
        $this->assertStringNotContainsString('public', (string) $invalid->headers->get('Cache-Control'));
    }

    // --- Panel admin ---------------------------------------------------------------------------

    public function test_owner_sets_opening_hours_and_holidays_in_store_settings(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'Asia/Jakarta'));
        $this->actingAs($this->user('owner'));

        // Form terisi bawaan Senin–Sabtu 09.00–17.00, Minggu tutup.
        Livewire::test(StoreSettings::class)
            ->assertFormSet(['schedule.1.is_open' => true, 'schedule.7.is_open' => false])
            // set() = perilaku browser; fillForm() akan mengganti seluruh array jadwal.
            ->set('data.store_name', 'Toko Pakan')
            ->set('data.store_whatsapp', '085155428611')
            ->set('data.schedule.6.open_time', '08:00')
            ->set('data.schedule.6.close_time', '12:00')
            ->set('data.closures', ['a' => ['date' => '2026-12-25', 'reason' => 'Natal']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(7, StoreSchedule::query()->count());
        $this->assertTrue(StoreSchedule::query()->where('day_of_week', 7)->value('is_closed'));
        $this->assertFalse(StoreSchedule::query()->where('day_of_week', 1)->value('is_closed')); // hari lain tetap buka
        $this->assertSame('08:00', substr((string) StoreSchedule::query()->where('day_of_week', 6)->value('open_time'), 0, 5));
        $this->assertSame('Natal', StoreClosure::query()->value('reason'));

        $this->getJson('/api/v1/store/public')
            ->assertJsonPath('data.whatsapp', '6285155428611')
            ->assertJsonPath('data.schedule.5', ['day' => 6, 'opens_at' => '08:00', 'closes_at' => '12:00', 'is_closed' => false])
            ->assertJsonPath('data.closures.0.date', '2026-12-25');
    }

    public function test_closing_time_must_be_after_opening_time(): void
    {
        $this->actingAs($this->user('owner'));

        Livewire::test(StoreSettings::class)
            ->set('data.schedule.2.open_time', '17:00')
            ->set('data.schedule.2.close_time', '09:00')
            ->call('save')
            ->assertHasFormErrors(['schedule.2.close_time']);

        $this->assertSame(0, StoreSchedule::query()->count());
    }

    public function test_admin_sets_category_slug_and_duplicates_are_rejected(): void
    {
        Category::query()->create(['name' => 'Kucing']);
        $this->actingAs($this->user('admin'));

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Kucing Dewasa', 'slug' => 'Kucing'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Ayam & Unggas', 'sort_order' => 5])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(5, Category::query()->where('slug', 'ayam-unggas')->value('sort_order'));
    }

    // --- Helpers -------------------------------------------------------------------------------

    private function product(string $title, array $attributes = []): Product
    {
        return Product::query()->create($attributes + [
            'title' => $title, 'price' => 10000, 'cost_price' => 8000, 'weight_gram' => 1000, 'is_active' => true,
        ]);
    }

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => ucfirst($role) . " {$n}", 'email' => "{$role}{$n}@example.test",
            'password' => 'password', 'role' => $role, 'is_active' => true,
        ]);
    }
}
