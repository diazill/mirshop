<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Filament\Resources\ApiTokenResource\Pages\ListApiTokens;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Filament\Widgets\SalesReviewAlert;
use App\Models\Customer;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Hari kerja aplikasi HP offline-first, termasuk upaya penyalahgunaan oleh orang dalam. */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;
    private User $cashier;
    private string $token;
    private Product $feed;
    private ProductUnit $kg;
    private ProductUnit $sack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->user('owner'));

        $this->device = Device::query()->create(['code' => 'K01', 'name' => 'HP kasir depan']);

        // HPP 8.000/kg → 400.000/karung.
        $this->feed = Product::query()->create(['title' => 'Pakan Ayam', 'price' => 9000, 'cost_price' => 8000, 'weight_gram' => 1000]);
        $this->kg = $this->feed->units()->create(['unit_id' => $this->unitId('KG'), 'is_base' => true, 'allow_decimal_qty' => true]);
        $this->sack = $this->feed->units()->create(['unit_id' => $this->unitId('KRG'), 'conversion_qty' => 50, 'barcode' => '899050']);
        $this->sack->prices()->create(['price_type' => 'eceran', 'min_qty' => 1, 'price' => 420000]);
        app(StockService::class)->increase($this->feed, 2, MovementType::Purchase, $this->sack); // 100 kg

        $this->cashier = $this->user('kasir');
        $this->token = $this->login($this->cashier, 'K01')->json('access_token');
    }

    public function test_login_binds_token_to_device_and_expires_in_30_days(): void
    {
        $token = PersonalAccessToken::query()->latest('id')->firstOrFail();

        $this->assertSame($this->device->id, $token->device_id);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertTrue($this->device->hasUser($this->cashier->id));

        $this->login($this->user('kasir'), 'ZZ9')->assertForbidden()->assertJsonPath('code', 'PERANGKAT_TIDAK_DIKENAL');
    }

    public function test_morning_full_catalog_contains_units_prices_and_stock_but_no_cost(): void
    {
        $response = $this->catalog()->assertOk()->assertJsonPath('full', true);

        $product = collect($response->json('products'))->firstWhere('id', $this->feed->id);
        $this->assertSame('100.000', $product['current_stock']);
        $this->assertArrayNotHasKey('cost_price', $product);
        $this->assertCount(2, $response->json('product_units'));
        $this->assertSame(420000, $response->json('product_prices.0.price'));
    }

    public function test_delta_catalog_returns_changes_and_deletions_since_last_sync(): void
    {
        $since = $this->catalog()->json('server_time');
        $this->travel(1)->minutes();

        $this->sack->prices()->first()->update(['price' => 430000]);
        $deletedUnit = $this->feed->units()->create(['unit_id' => $this->unitId('BKS'), 'conversion_qty' => 1]);
        $deletedUnit->delete();
        $this->feed->delete();

        $response = $this->catalog($since)->assertOk()->assertJsonPath('full', false);

        $this->assertSame(430000, $response->json('product_prices.0.price'));
        $this->assertTrue(collect($response->json('products'))->firstWhere('id', $this->feed->id)['is_deleted']);
        $this->assertContains(['table' => 'product_units', 'id' => $deletedUnit->id], $response->json('deletions'));
    }

    public function test_sync_accepts_offline_sales_and_reports_per_sale(): void
    {
        $this->sync([
            $this->sale('a-1', 'K01-20261117-0001', now()->subHours(2), [[$this->sack, 1, 420000]], paid: 500000),
            $this->sale('a-2', 'K01-20261117-0002', now()->subHour(), [[$this->kg, '2.5', 9000]]),
        ])->assertOk()
            ->assertJsonPath('summary.created', 2)
            ->assertJsonPath('summary.rejected', 0);

        $first = Transaction::query()->where('invoice_no', 'K01-20261117-0001')->firstOrFail();
        $this->assertSame('offline', $first->source);
        $this->assertSame($this->device->id, $first->device_id);
        $this->assertSame(80000, $first->change_amount);
        $this->assertFalse($first->needs_review);
        $this->assertSame('47.500', $this->feed->refresh()->current_stock);
    }

    public function test_late_sale_is_recorded_on_its_own_day_and_flagged(): void
    {
        $occurred = now()->subHours(30);

        $this->sync([$this->sale('l-1', 'K01-20261116-0001', $occurred, [[$this->kg, 1, 9000]])])
            ->assertJsonPath('data.0.status', 'created')
            ->assertJsonPath('data.0.late_sync', true)
            ->assertJsonPath('data.0.needs_review', true);

        $this->api('get', '/api/v1/pos/transactions?date=' . $occurred->toDateString())->assertJsonPath('total', 1);
    }

    public function test_sale_older_than_three_days_is_rejected(): void
    {
        $this->sync([$this->sale('o-1', 'K01-20261110-0001', now()->subDays(4), [[$this->kg, 1, 9000]])])
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.code', 'TRANSAKSI_TIDAK_VALID');

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_pressing_sync_twice_does_not_duplicate_sales(): void
    {
        $sales = [$this->sale('b-1', 'K01-20261117-0001', now(), [[$this->sack, 1, 420000]])];

        $this->sync($sales)->assertJsonPath('summary.created', 1);
        $this->sync($sales)->assertJsonPath('summary.duplicate', 1)->assertJsonPath('summary.created', 0);

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('50.000', $this->feed->refresh()->current_stock);
    }

    public function test_price_below_cost_is_flagged_for_the_owner(): void
    {
        // Kasir menerima 420.000 tetapi mencatat 1.000.
        $this->sync([$this->sale('c-1', 'K01-20261117-0001', now(), [[$this->sack, 1, 1000]])])
            ->assertJsonPath('data.0.status', 'created')
            ->assertJsonPath('data.0.below_cost', true)
            ->assertJsonPath('data.0.price_mismatch', true);

        $item = Transaction::query()->firstOrFail()->transactionItems()->firstOrFail();
        $this->assertSame(1000, $item->price_at_time);
        $this->assertSame(420000, $item->official_price);

        $this->actingAs($this->user('owner'));
        Livewire::test(SalesReviewAlert::class)->assertSee('Di bawah HPP')->assertSee('Cek kasir dan uang yang diterima');
    }

    public function test_small_price_difference_above_cost_is_flagged_but_not_below_cost(): void
    {
        $this->sync([$this->sale('c-2', 'K01-20261117-0001', now(), [[$this->sack, 1, 410000]])])
            ->assertJsonPath('data.0.price_mismatch', true)
            ->assertJsonPath('data.0.below_cost', false);
    }

    public function test_offline_sale_beyond_stock_is_accepted_with_negative_stock_and_flagged(): void
    {
        $this->sync([$this->sale('d-1', 'K01-20261117-0001', now(), [[$this->sack, 3, 420000]])])
            ->assertJsonPath('data.0.status', 'created')
            ->assertJsonPath('data.0.stock_conflict', true);

        $this->assertSame('-50.000', $this->feed->refresh()->current_stock);
        $this->artisan('stock:verify')->assertSuccessful();
    }

    public function test_one_bad_sale_does_not_block_the_others(): void
    {
        $this->sync([
            $this->sale('e-1', 'K01-20261117-0001', now(), [[$this->sack, '0.5', 420000]]),
            $this->sale('e-2', 'X99-20261117-0001', now(), [[$this->kg, 1, 9000]]),
            $this->sale('e-3', 'K01-20261117-0003', now()->addDay(), [[$this->kg, 1, 9000]]),
            $this->sale('e-4', 'K01-20261117-0004', now(), [[$this->kg, 1, 9000]]),
        ])->assertOk()
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.rejected', 3);

        $this->assertSame(['K01-20261117-0004'], Transaction::query()->pluck('invoice_no')->all());
    }

    public function test_invoice_number_reused_by_another_sale_is_rejected(): void
    {
        $this->sync([$this->sale('f-1', 'K01-20261117-0001', now(), [[$this->kg, 1, 9000]])]);

        $this->sync([$this->sale('f-2', 'K01-20261117-0001', now(), [[$this->kg, 2, 9000]])])
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.code', 'NOMOR_NOTA_BENTROK');
    }

    public function test_cashier_id_must_belong_to_someone_who_logged_in_on_this_phone(): void
    {
        $colleague = $this->user('kasir');
        $this->login($colleague, 'K01');
        $stranger = $this->user('owner');

        $this->sync([
            $this->sale('g-1', 'K01-20261117-0001', now(), [[$this->kg, 1, 9000]], cashierId: $colleague->id),
            $this->sale('g-2', 'K01-20261117-0002', now(), [[$this->kg, 1, 9000]], cashierId: $stranger->id),
        ])->assertJsonPath('data.0.status', 'created')
            ->assertJsonPath('data.1.status', 'rejected');

        $transaction = Transaction::query()->firstOrFail();
        $this->assertSame($colleague->id, $transaction->user_id);
        $this->assertSame($this->cashier->id, $transaction->synced_by);
    }

    public function test_device_code_header_cannot_impersonate_another_phone(): void
    {
        Device::query()->create(['code' => 'K02', 'name' => 'HP lain']);

        $this->api('get', '/api/v1/sync/catalog', headers: ['X-Device-Code' => 'K02'])
            ->assertForbidden()
            ->assertJsonPath('code', 'PERANGKAT_TIDAK_COCOK');

        // Token tanpa perangkat (login tanpa device_code) tidak bisa sinkron sama sekali.
        $plain = $this->login($this->user('kasir'))->json('access_token');
        $this->api('get', '/api/v1/sync/catalog', token: $plain, headers: ['X-Device-Code' => 'K01'])
            ->assertForbidden()
            ->assertJsonPath('code', 'PERANGKAT_TIDAK_DIKENAL');
    }

    public function test_deactivating_a_lost_phone_blocks_all_its_tokens(): void
    {
        $this->catalog()->assertOk();

        $this->device->update(['is_active' => false]);

        $this->catalog()->assertForbidden()->assertJsonPath('code', 'PERANGKAT_NONAKTIF');
        $this->api('get', '/api/v1/pos/transactions')->assertForbidden()->assertJsonPath('code', 'PERANGKAT_NONAKTIF');
        $this->api('get', '/api/v1/me')->assertForbidden();
    }

    public function test_warehouse_can_pull_catalog_but_not_push_sales(): void
    {
        $gudang = $this->login($this->user('gudang'), 'K01')->json('access_token');

        $this->api('get', '/api/v1/sync/catalog', token: $gudang)->assertOk();
        $this->api('post', '/api/v1/sync/transactions', ['transactions' => [
            $this->sale('h-1', 'K01-20261117-0001', now(), [[$this->kg, 1, 9000]]),
        ]], token: $gudang)->assertForbidden();
    }

    public function test_oversized_numbers_and_batches_are_rejected_without_server_error(): void
    {
        $this->sync([$this->sale('n-1', 'K01-20261117-0001', now(), [[$this->kg, 1, 3_000_000_000]])])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDASI_GAGAL');

        // 99.999 kg × Rp100 juta melewati batas total: ditolak per nota, bukan error 500.
        $this->sync([$this->sale('n-2', 'K01-20261117-0002', now(), [[$this->kg, 99999, 100_000_000]])])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'rejected');

        $tooMany = [];
        for ($i = 1; $i <= 11; $i++) {
            $tooMany[] = $this->sale("big-{$i}", sprintf('K01-20261117-%04d', 100 + $i), now(), array_fill(0, 200, [$this->kg, 1, 9000]));
        }
        $this->sync($tooMany)->assertStatus(422)->assertJsonPath('code', 'SINKRON_TERLALU_BESAR');

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_api_errors_use_the_same_shape(): void
    {
        $this->getJson('/api/v1/tidak-ada')->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN')->assertJsonMissingPath('exception');
        $this->api('get', '/api/v1/pos/transactions/999999')->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->deleteJson('/api/v1/products')->assertStatus(405)->assertJsonPath('code', 'METODE_TIDAK_DIDUKUNG');
    }

    public function test_offline_opname_keeps_sales_made_after_counting(): void
    {
        $stock = app(StockService::class);

        $stock->decrease($this->feed, 10, MovementType::Sale);
        $stock->opname($this->feed, 98, expectedBaseQty: 100);

        $this->assertSame('88.000', $this->feed->refresh()->current_stock);
    }

    public function test_owner_reviews_flagged_sales_and_revokes_a_token(): void
    {
        $this->sync([
            $this->sale('i-1', 'K01-20261117-0001', now(), [[$this->sack, 1, 400000]]),
            $this->sale('i-2', 'K01-20261117-0002', now(), [[$this->kg, 1, 9000]]),
        ]);
        $flagged = Transaction::query()->where('invoice_no', 'K01-20261117-0001')->firstOrFail();
        $clean = Transaction::query()->where('invoice_no', 'K01-20261117-0002')->firstOrFail();

        $this->actingAs($this->user('owner'));

        Livewire::test(ListTransactions::class)
            ->filterTable('needs_review', true)
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$clean])
            ->callTableAction('markReviewed', $flagged);

        $this->assertFalse($flagged->refresh()->needs_review);

        $token = PersonalAccessToken::query()->where('tokenable_id', $this->cashier->id)->firstOrFail();
        Livewire::test(ListApiTokens::class)
            ->assertCanSeeTableRecords([$token])
            ->callTableAction('delete', $token);

        $this->catalog()->assertUnauthorized();
    }

    public function test_catalog_carries_active_customers_store_info_and_customer_deletions(): void
    {
        $budi = Customer::query()->create(['name' => 'Budi', 'phone' => '081234567890']);
        $rina = Customer::query()->create(['name' => 'Rina']);
        $dodi = Customer::query()->create(['name' => 'Dodi']);
        $old = Customer::query()->create(['name' => 'Lama', 'is_active' => false]);

        $full = $this->catalog()->assertOk();
        $this->assertEqualsCanonicalizing([$budi->id, $rina->id, $dodi->id], array_column($full->json('customers'), 'id'));
        $this->assertSame('081234567890', collect($full->json('customers'))->firstWhere('id', $budi->id)['phone']);
        $this->assertSame('Terima kasih', $full->json('store.receipt_footer'));

        $since = $full->json('server_time');
        $this->travel(1)->minutes();

        $budi->update(['name' => 'Budi Santoso']);
        $rina->update(['is_active' => false]);
        $dodi->delete();
        $old->forceDelete();

        $delta = $this->catalog($since)->assertOk();
        $this->assertSame([['id' => $budi->id, 'name' => 'Budi Santoso']], array_map(
            fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']],
            $delta->json('customers'),
        ));
        $removed = collect($delta->json('deletions'))->where('table', 'customers')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$rina->id, $dodi->id, $old->id], $removed);

        // Gudang tidak menerima data pelanggan.
        $warehouse = $this->login($this->user('gudang'), 'K01')->json('access_token');
        $this->api('get', '/api/v1/sync/catalog', [], $warehouse)->assertOk()->assertJsonCount(0, 'customers');
    }

    public function test_offline_sale_keeps_its_customer_and_removed_customer_does_not_reject_the_sale(): void
    {
        $budi = Customer::query()->create(['name' => 'Budi']);
        $gone = Customer::query()->create(['name' => 'Pak Hilang']);
        $gone->delete();
        $inactive = Customer::query()->create(['name' => 'Bu Libur', 'is_active' => false]);

        // Satu nota yang sama persis, dipakai lagi saat kirim ulang (waktu tidak boleh bergeser).
        $firstSale = $this->sale('c-1', 'K01-20261120-0001', now()->subHour(), [[$this->kg, 1, 9000]]);

        $result = $this->sync([
            ['customer_id' => $budi->id] + $firstSale,
            ['customer_id' => $gone->id] + $this->sale('c-2', 'K01-20261120-0002', now()->subMinutes(50), [[$this->kg, 1, 9000]]),
            ['customer_id' => $inactive->id] + $this->sale('c-3', 'K01-20261120-0003', now()->subMinutes(40), [[$this->kg, 1, 9000]]),
            ['customer_id' => 999999] + $this->sale('c-4', 'K01-20261120-0004', now()->subMinutes(30), [[$this->kg, 1, 9000]]),
        ])->assertOk()->assertJsonPath('summary.created', 4);

        $result->assertJsonPath('data.0.customer_id', $budi->id)
            ->assertJsonPath('data.0.customer_dropped', false)
            ->assertJsonPath('data.0.needs_review', false);

        foreach ([1, 2, 3] as $i) {
            $result->assertJsonPath("data.{$i}.customer_id", null)
                ->assertJsonPath("data.{$i}.customer_dropped", true)
                ->assertJsonPath("data.{$i}.needs_review", true);
        }

        $this->assertSame('Pak Hilang', Transaction::query()->where('invoice_no', 'K01-20261120-0002')->value('customer_name'));
        $this->assertSame('96.000', $this->feed->refresh()->current_stock);

        // Kirim ulang: tetap duplikat, tidak tercatat dua kali.
        $this->sync([['customer_id' => $budi->id] + $firstSale])
            ->assertJsonPath('data.0.status', 'duplicate');

        $this->assertSame(['Pelanggan tidak aktif'], \App\Filament\Resources\TransactionResource::reviewFlags(
            Transaction::query()->where('invoice_no', 'K01-20261120-0003')->sole(),
        ));
    }

    public function test_offline_time_with_phone_timezone_is_stored_as_the_real_moment(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 05:00', 'UTC')); // 12:00 WIB
        $token = $this->login($this->cashier, 'K01')->json('access_token');

        // HP mengirim jam lokal WIB dengan offset: 09:15+07:00 = 02:15 UTC.
        $sale = $this->sale('tz-1', 'K01-20261120-0101', '2026-11-20 05:00', [[$this->kg, 1, 9000]]);
        $sale['occurred_at'] = '2026-11-20T09:15:00+07:00';

        $this->api('post', '/api/v1/sync/transactions', ['transactions' => [$sale]], $token)
            ->assertJsonPath('data.0.status', 'created');

        $this->assertSame('2026-11-20 02:15:00', Transaction::query()->sole()->occurred_at->utc()->format('Y-m-d H:i:s'));

        $this->api('get', '/api/v1/pos/summary', [], $token)->assertJsonPath('hourly.0.hour', 9);
    }

    private function login(User $user, ?string $deviceCode = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', array_filter([
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'HP test',
            'device_code' => $deviceCode,
        ]));
    }

    private function api(string $method, string $uri, array $data = [], ?string $token = null, array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)
            ->withHeaders($headers)
            ->json(strtoupper($method), $uri, $data);
    }

    private function catalog(?string $since = null)
    {
        return $this->api('get', '/api/v1/sync/catalog' . ($since ? '?since=' . urlencode($since) : ''));
    }

    private function sync(array $sales)
    {
        return $this->api('post', '/api/v1/sync/transactions', ['transactions' => $sales]);
    }

    /** @param array<int, array{0: ProductUnit, 1: int|string, 2: int}> $lines */
    private function sale(string $clientId, string $invoice, $occurredAt, array $lines, ?int $paid = null, ?int $cashierId = null): array
    {
        return array_filter([
            'client_id' => $clientId,
            'invoice_no' => $invoice,
            'occurred_at' => Carbon::parse($occurredAt)->toIso8601String(),
            'payment_method' => $paid ? 'tunai' : 'qris',
            'paid_amount' => $paid,
            'cashier_id' => $cashierId,
            'items' => array_map(fn (array $l): array => [
                'product_unit_id' => $l[0]->id, 'qty' => $l[1], 'price' => $l[2],
            ], $lines),
        ], fn ($v) => $v !== null);
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

    private function unitId(string $code): int
    {
        return Unit::query()->where('code', $code)->value('id');
    }
}
