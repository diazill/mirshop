<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Filament\Pages\StoreSettings;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\CustomerResource\RelationManagers\TransactionsRelationManager;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Kebutuhan Mode Kasir: pelanggan, nota berpelanggan, kepala struk, ringkasan hari ini. */
class CashierCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Product $catFood;
    private ProductUnit $pack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catFood = Product::query()->create(['title' => 'Makanan Kucing 1 kg', 'price' => 25000, 'cost_price' => 20000, 'weight_gram' => 1000]);
        $this->pack = $this->catFood->units()->create(['unit_id' => Unit::query()->where('code', 'BKS')->value('id'), 'is_base' => true]);

        $this->actingAs($this->user('owner'));
        app(StockService::class)->increase($this->catFood, 100, MovementType::Purchase);
    }

    // --- Pelanggan ---------------------------------------------------------------------------

    public function test_cashier_searches_active_customers_by_name_or_any_phone_format(): void
    {
        $budi = $this->customer('Budi Santoso', '081234567890');
        $siti = $this->customer('Siti Aminah', '+62 811-111-222');
        $this->customer('Budi Lama', '0899999999', active: false);
        $this->customer('Diskon 100%');

        $this->loginAs('kasir');

        $this->assertSame([$budi->id], $this->search('budi'));
        $this->assertSame([$budi->id], $this->search('BUDI san'));
        $this->assertSame([$siti->id], $this->search('0811 111'));
        $this->assertSame([$siti->id], $this->search('+62811'));
        $this->assertSame([], $this->search('_'));

        $this->getJson('/api/v1/customers?q=siti')
            ->assertOk()
            ->assertJsonPath('data.0', [
                'id' => $siti->id, 'name' => 'Siti Aminah', 'phone' => '0811111222', 'address' => null, 'is_active' => true,
            ]);
    }

    public function test_quick_add_is_idempotent_and_shows_up_in_the_admin_panel(): void
    {
        $cashier = $this->loginAs('kasir');

        $first = $this->addCustomer(['name' => '  Pak   Joko ', 'phone' => '+62 812-0000-1111'], 'hp-cust-1')
            ->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('data.name', 'Pak Joko')
            ->assertJsonPath('data.phone', '081200001111');

        $this->addCustomer(['name' => '  Pak   Joko ', 'phone' => '+62 812-0000-1111'], 'hp-cust-1')
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->addCustomer(['name' => 'Orang Lain'], 'hp-cust-1')
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_BENTROK');

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($cashier->id, Customer::query()->value('created_by'));

        $this->flushHeaders()->postJson('/api/v1/customers', ['name' => 'Tanpa Kunci'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_WAJIB');
        $this->addCustomer(['name' => '   '], 'k2')->assertUnprocessable()->assertJsonPath('code', 'VALIDASI_GAGAL');
        $this->addCustomer(['name' => 'Salah', 'phone' => '08ab'], 'k3')->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->actingAs($this->user('admin'), 'web');
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords(Customer::query()->get())
            ->assertSee('Pak Joko');
    }

    public function test_phone_already_used_returns_409_with_that_customer_so_the_app_can_use_it(): void
    {
        $existing = $this->customer('Bu Rina', '0813-5555-6666');
        $this->loginAs('kasir');

        $this->addCustomer(['name' => 'Rina', 'phone' => '+6281355556666'], 'k-dup')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PELANGGAN_SUDAH_ADA')
            ->assertJsonPath('details.id', $existing->id)
            ->assertJsonPath('details.name', 'Bu Rina')
            ->assertJsonPath('details.phone', '081355556666');

        // Nomor pelanggan yang sudah dihapus boleh dipakai lagi.
        $existing->delete();
        $this->addCustomer(['name' => 'Rina Baru', 'phone' => '081355556666'], 'k-dup-2')->assertCreated();
    }

    public function test_only_cashier_admin_and_owner_can_use_customers(): void
    {
        $customer = $this->customer('Budi');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/customers')->assertUnauthorized();

        $this->loginAs('gudang');
        $this->getJson('/api/v1/customers')->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');
        $this->addCustomer(['name' => 'X'], 'g-1')->assertForbidden();
        $this->getJson("/api/v1/customers/{$customer->id}")->assertForbidden();

        foreach (['kasir', 'admin', 'owner'] as $role) {
            $this->loginAs($role);
            $this->getJson("/api/v1/customers/{$customer->id}")->assertOk()->assertJsonPath('data.name', 'Budi');
        }

        $customer->delete();
        $this->getJson("/api/v1/customers/{$customer->id}")->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
    }

    // --- Nota berpelanggan -------------------------------------------------------------------

    public function test_sale_with_customer_is_recorded_and_appears_in_customer_history(): void
    {
        $customer = $this->customer('Budi', '081234567890');
        $cashier = $this->loginAs('kasir');

        $response = $this->postSale(['customer_id' => $customer->id])
            ->assertCreated()
            ->assertJsonPath('data.customer', ['id' => $customer->id, 'name' => 'Budi']);

        $invoice = $response->json('data.invoice_no');

        $this->getJson('/api/v1/pos/transactions/' . $response->json('data.id'))
            ->assertJsonPath('data.customer.name', 'Budi');
        $this->getJson('/api/v1/pos/transactions')->assertJsonPath('data.0.customer.id', $customer->id);

        $this->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.recent_transactions.0.invoice_no', $invoice)
            ->assertJsonPath('data.recent_transactions.0.total_amount', 50000);

        // Kasir lain tidak melihat nota kasir ini di riwayat pelanggan; owner melihat semua.
        $this->loginAs('kasir');
        $this->getJson("/api/v1/customers/{$customer->id}")->assertJsonCount(0, 'data.recent_transactions');
        $this->loginAs('owner');
        $this->getJson("/api/v1/customers/{$customer->id}")->assertJsonCount(1, 'data.recent_transactions');

        // Nama di nota tetap seperti saat transaksi walau pelanggan diganti namanya.
        $customer->update(['name' => 'Budi Santoso']);
        $this->assertSame('Budi', Transaction::query()->value('customer_name'));

        $this->actingAs($this->user('admin'), 'web');
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
            ->assertCanSeeTableRecords(Transaction::query()->get());

        $this->assertSame($cashier->id, Transaction::query()->value('user_id'));
    }

    public function test_sale_without_customer_behaves_exactly_as_before(): void
    {
        $this->loginAs('kasir');

        $response = $this->postSale([], 'same-key')->assertCreated()->assertJsonPath('data.customer', null);
        $this->postSale([], 'same-key')->assertOk()->assertJsonPath('data.id', $response->json('data.id'));

        $transaction = Transaction::query()->sole();
        $this->assertNull($transaction->customer_id);
        $this->assertFalse($transaction->needs_review);
        // Hash idempotensi sama seperti sebelum fitur pelanggan: kiriman ulang dari aplikasi versi lama tetap cocok.
        $this->assertSame(
            hash('sha256', json_encode([[['product_unit_id' => $this->pack->id, 'qty' => 2]], 'qris', null, null])),
            $transaction->getRawOriginal('request_hash'),
        );
    }

    public function test_inactive_deleted_or_unknown_customer_is_rejected_online_without_touching_stock(): void
    {
        $inactive = $this->customer('Nonaktif', active: false);
        $deleted = $this->customer('Terhapus');
        $deleted->delete();
        $this->loginAs('kasir');

        foreach ([$inactive->id, $deleted->id, 999999] as $id) {
            $this->postSale(['customer_id' => $id])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'PELANGGAN_TIDAK_AKTIF');
        }

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('100.000', $this->catFood->refresh()->current_stock);
    }

    // --- Ringkasan hari ini ------------------------------------------------------------------

    public function test_daily_summary_matches_the_store_day_and_ignores_cancelled_sales(): void
    {
        $kasirA = $this->user('kasir');
        $kasirB = $this->user('kasir');

        // Semua waktu UTC; toko WIB (UTC+7).
        $this->sellAt($kasirA, '2026-11-19 23:30', 1, 'tunai', 30000); // 06:30 WIB tgl 20
        $this->sellAt($kasirA, '2026-11-20 02:10', 2, 'qris');          // 09:10 WIB
        $this->sellAt($kasirA, '2026-11-20 02:50', 1, 'transfer');      // 09:50 WIB
        $cancelled = $this->sellAt($kasirA, '2026-11-20 03:00', 4, 'qris');
        $this->sellAt($kasirB, '2026-11-20 03:30', 3, 'tunai', 100000); // kasir lain
        $this->sellAt($kasirA, '2026-11-20 17:30', 1, 'qris');          // 00:30 WIB tgl 21

        app(TransactionService::class)->cancel($cancelled, 'Salah input', User::query()->where('role', 'owner')->first());

        $this->travelTo(Carbon::parse('2026-11-20 05:00', 'UTC'));

        Sanctum::actingAs($kasirA);
        $this->getJson('/api/v1/pos/summary')
            ->assertOk()
            ->assertExactJson([
                'date' => '2026-11-20',
                'timezone' => 'Asia/Jakarta',
                'count' => 3,
                'total' => 100000,
                'by_method' => ['tunai' => 25000, 'transfer' => 25000, 'qris' => 50000],
                'cancelled_count' => 1,
                'hourly' => [
                    ['hour' => 6, 'count' => 1, 'total' => 25000],
                    ['hour' => 9, 'count' => 2, 'total' => 75000],
                ],
            ]);

        Sanctum::actingAs(User::query()->where('role', 'owner')->first());
        $this->getJson('/api/v1/pos/summary?date=2026-11-20')
            ->assertJsonPath('count', 4)
            ->assertJsonPath('total', 175000)
            ->assertJsonPath('by_method.tunai', 100000)
            ->assertJsonPath('hourly.2', ['hour' => 10, 'count' => 1, 'total' => 75000]);

        $this->getJson('/api/v1/pos/summary?date=2026-11-21')->assertJsonPath('count', 1)->assertJsonPath('hourly.0.hour', 0);
        $this->getJson('/api/v1/pos/summary?date=20-11-2026')->assertUnprocessable();

        $this->loginAs('gudang');
        $this->getJson('/api/v1/pos/summary')->assertForbidden();
    }

    // --- Info toko ---------------------------------------------------------------------------

    public function test_owner_sets_receipt_header_and_cashier_reads_it(): void
    {
        $this->loginAs('gudang');
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('data.name', config('app.name'))
            ->assertJsonPath('data.receipt_footer', 'Terima kasih')
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.timezone', 'Asia/Jakarta');

        Storage::fake('s3');
        $this->actingAs($this->user('owner'), 'web');
        Livewire::test(StoreSettings::class)
            ->fillForm([
                'store_name' => 'Mir Pakan Ternak',
                'store_address' => 'Jl. Pasar No. 1',
                'store_phone' => '0812-3456-7890',
                'receipt_footer' => 'Barang yang sudah dibeli tidak dapat ditukar',
                'store_timezone' => 'Asia/Makassar',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->loginAs('kasir');
        $this->getJson('/api/v1/store')->assertExactJson(['data' => [
            'name' => 'Mir Pakan Ternak',
            'address' => 'Jl. Pasar No. 1',
            'phone' => '0812-3456-7890',
            'receipt_footer' => 'Barang yang sudah dibeli tidak dapat ditukar',
            'logo_url' => null,
            'timezone' => 'Asia/Makassar',
        ]]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/store')->assertUnauthorized();
    }

    public function test_only_owner_opens_store_settings_page(): void
    {
        \App\Support\StoreProfile::save(['store_name' => 'Mir Pakan Ternak']);

        $this->actingAs($this->user('owner'), 'web');
        $this->get(StoreSettings::getUrl())->assertOk()->assertSee('Mir Pakan Ternak')->assertSee('Zona waktu toko');
        $this->get('/admin')->assertSee('Pengaturan Toko')->assertSee('Pelanggan');
    }

    public function test_admin_cannot_open_store_settings_page(): void
    {
        $this->actingAs($this->user('admin'), 'web');
        $this->get(StoreSettings::getUrl())->assertForbidden();
    }

    // --- Panel admin -------------------------------------------------------------------------

    public function test_admin_manages_customers_in_the_panel(): void
    {
        $this->customer('Bu Rina', '081355556666');
        $this->actingAs($this->user('admin'));

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Rina Kembar', 'phone' => '+62 813 5555 6666'])
            ->call('create')
            ->assertHasFormErrors(['phone']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Pak Dodi', 'phone' => '0857 1234 5678', 'address' => 'Desa Sukamaju'])
            ->call('create')
            ->assertHasNoFormErrors();

        $dodi = Customer::query()->where('name', 'Pak Dodi')->sole();
        $this->assertSame('085712345678', $dodi->phone);
        $this->assertTrue($dodi->is_active);

        Livewire::test(ListCustomers::class)->callTableAction('toggleActive', $dodi);
        $this->assertFalse($dodi->refresh()->is_active);

        // Admin menonaktifkan, hanya owner yang boleh menghapus.
        Livewire::test(ListCustomers::class)->assertTableActionHidden('delete', $dodi);
    }

    // --- Helpers -----------------------------------------------------------------------------

    /** @return array<int, int> */
    private function search(string $q): array
    {
        return array_column($this->getJson('/api/v1/customers?q=' . urlencode($q))->assertOk()->json('data'), 'id');
    }

    private function addCustomer(array $body, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/customers', $body);
    }

    private function postSale(array $extra = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) str()->uuid())
            ->postJson('/api/v1/pos/transactions', [
                'items' => [['product_unit_id' => $this->pack->id, 'qty' => 2]],
                'payment_method' => 'qris',
            ] + $extra);
    }

    private function sellAt(User $cashier, string $utc, int $qty, string $method, ?int $paid = null): Transaction
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));

        $sale = app(TransactionService::class)->createSale(
            cashier: $cashier,
            lines: [['product_unit_id' => $this->pack->id, 'qty' => $qty]],
            method: \App\Enums\PaymentMethod::from($method),
            paidAmount: $paid,
        );

        $this->travelBack();

        return $sale;
    }

    private function customer(string $name, ?string $phone = null, bool $active = true): Customer
    {
        return Customer::query()->create(['name' => $name, 'phone' => $phone, 'is_active' => $active]);
    }

    private function loginAs(string $role): User
    {
        $user = $this->user($role);
        Sanctum::actingAs($user);

        return $user;
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
