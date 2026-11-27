<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\StoreSettings;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/** Audit log: login staf dan perubahan data penting, tanpa password/token. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_logins_are_recorded_with_the_reason_for_failure(): void
    {
        $owner = $this->user('owner', 'pemilik');
        $cashier = $this->user('kasir', 'kasir1');

        $start = AuditLog::query()->max('id') ?? 0; // abaikan catatan "dibuat" dari persiapan data

        $this->panelLogin('pemilik', 'Tebakan#4821')->assertHasErrors();
        $this->panelLogin('kasir1', 'password')->assertHasErrors();
        $this->panelLogin('tidakada', 'password')->assertHasErrors();
        $this->panelLogin('PEMILIK', 'password')->assertHasNoErrors();

        $logs = AuditLog::query()->where('id', '>', $start)->orderBy('id')->get();

        $this->assertSame(
            [
                ['login_failed', $owner->id, 'pemilik', 'password_salah', null],
                ['login_failed', $cashier->id, 'kasir1', 'tanpa_akses_panel', null],
                ['login_failed', null, 'tidakada', 'akun_tidak_ditemukan', null],
                ['login_success', $owner->id, 'pemilik', null, $owner->id],
            ],
            $logs->map(fn (AuditLog $l): array => [$l->action, $l->subject_id, $l->subject_label, $l->context['reason'] ?? null, $l->user_id])->all(),
        );
        $this->assertSame('panel', $logs->last()->channel);
        $this->assertStringNotContainsString('Tebakan#4821', $logs->toJson()); // password yang diketik tidak tersimpan
    }

    public function test_app_logins_and_logout_are_recorded_without_secrets(): void
    {
        Device::query()->create(['code' => 'K01', 'name' => 'HP kasir']);
        $cashier = $this->user('kasir', 'kasir1');
        $start = AuditLog::query()->max('id') ?? 0;

        $this->postJson('/api/v1/auth/login', ['login' => 'kasir1', 'password' => 'Tebakan#4821', 'device_name' => 'HP'])->assertUnauthorized();
        $token = $this->postJson('/api/v1/auth/login', ['login' => 'kasir1', 'password' => 'password', 'device_name' => 'HP', 'device_code' => 'k01'])
            ->assertOk()
            ->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $logs = AuditLog::query()->where('id', '>', $start)->orderBy('id')->get();
        $this->assertSame(['login_failed', 'login_success', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame('password_salah', $logs[0]->context['reason']);
        $this->assertSame('K01', $logs[1]->context['device_code']);
        $this->assertSame([$cashier->id, 'api'], [$logs[1]->user_id, $logs[1]->channel]);
        $this->assertSame(['api_token', $cashier->id], [$logs[2]->subject_type, $logs[2]->user_id]);

        $json = $logs->toJson();
        $this->assertStringNotContainsString(explode('|', $token)[1], $json);
        $this->assertStringNotContainsString('Tebakan#4821', $json);
    }

    public function test_price_changes_are_recorded_but_stock_movements_are_not(): void
    {
        $admin = $this->user('admin', 'admin1');
        $this->actingAs($admin);

        $product = Product::query()->create(['title' => 'Pakan Ayam', 'price' => 9000, 'cost_price' => 8000, 'weight_gram' => 1000]);
        $product->units()->create(['unit_id' => Unit::query()->where('code', 'KG')->value('id'), 'is_base' => true]);
        $before = AuditLog::query()->count();

        app(StockService::class)->increase($product, 10, MovementType::Purchase);
        $this->assertSame($before, AuditLog::query()->count());

        $product->update(['price' => 9500, 'cost_price' => 8000]);

        $log = AuditLog::query()->latest('id')->first();
        $this->assertSame(['updated', 'product', $product->id, 'Pakan Ayam', $admin->id], [$log->action, $log->subject_type, $log->subject_id, $log->subject_label, $log->user_id]);
        $this->assertSame(['price' => [9000, 9500]], $log->changes);

        $created = AuditLog::query()->where('subject_type', 'product_unit')->where('action', 'created')->sole();
        $this->assertSame('Pakan Ayam (Kg)', $created->subject_label);
    }

    public function test_user_changes_hide_the_password(): void
    {
        $this->actingAs($this->user('owner', 'pemilik'));
        $staff = $this->user('kasir', 'budi');

        $staff->update(['role' => 'admin', 'password' => 'rahasia-baru']);

        $log = AuditLog::query()->where('subject_id', $staff->id)->where('action', 'updated')->sole();
        $this->assertSame(['kasir', 'admin'], $log->changes['role']);
        $this->assertSame(['•••', '••• (diubah)'], $log->changes['password']);
        $this->assertStringNotContainsString('$2y$', json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_store_settings_record_each_changed_setting_and_one_entry_for_hours(): void
    {
        $this->actingAs($this->user('owner', 'pemilik'));

        // set() = perilaku browser (wire:model). fillForm() mengganti seluruh array jadwal, jadi tidak dipakai.
        Livewire::test(StoreSettings::class)
            ->set('data.store_name', 'Toko Pakan')
            ->set('data.schedule.6.close_time', '12:00')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, AuditLog::query()->where('subject_type', 'setting')->where('subject_label', 'store_name')->count());
        $this->assertSame(1, AuditLog::query()->where('subject_type', 'store_hours')->count());

        // Simpan lagi tanpa perubahan jadwal: tidak ada catatan jam buka baru.
        Livewire::test(StoreSettings::class)->set('data.schedule.2.close_time', '16:00')->call('save');
        $hours = AuditLog::query()->where('subject_type', 'store_hours')->latest('id')->first();
        $this->assertSame(['Selasa' => ['09:00–17:00', '09:00–16:00']], $hours->changes);

        Livewire::test(StoreSettings::class)->call('save');
        $this->assertSame(2, AuditLog::query()->where('subject_type', 'store_hours')->count());
    }

    public function test_owner_revokes_app_logins_and_it_is_recorded(): void
    {
        $this->actingAs($this->user('owner', 'pemilik'));
        $staff = $this->user('kasir', 'budi');
        $staff->createToken('HP 1');
        $staff->createToken('HP 2');

        Livewire::test(ListUsers::class)->callTableAction('revokeTokens', $staff);

        $log = AuditLog::query()->where('action', 'token_revoked')->sole();
        $this->assertSame([$staff->id, 2], [$log->subject_id, $log->context['jumlah_login']]);
    }

    public function test_audit_log_is_read_only_owner_only_and_pruned_after_two_years(): void
    {
        $owner = $this->user('owner', 'pemilik');
        $this->actingAs($owner);
        $owner->update(['name' => 'Pemilik Toko']);
        $log = AuditLog::query()->latest('id')->first();

        Livewire::test(ListAuditLogs::class)->assertCanSeeTableRecords([$log])->assertSee('Pemilik Toko');

        try {
            $log->update(['action' => 'deleted']);
            $this->fail('Audit log seharusnya tidak bisa diubah.');
        } catch (LogicException) {
        }

        $this->travel(AuditLog::RETENTION_DAYS + 1)->days();
        $owner->update(['name' => 'Pemilik Baru']);
        Artisan::call('model:prune', ['--model' => [AuditLog::class]]);
        $this->assertFalse(AuditLog::query()->whereKey($log->id)->exists());
        $this->assertSame(1, AuditLog::query()->count());

        $this->actingAs($this->user('admin', 'admin1'));
        $this->assertFalse(AuditLogResource::canViewAny());
    }

    private function panelLogin(string $login, string $password)
    {
        $this->app['auth']->forgetGuards();

        return Livewire::test(Login::class)
            ->fillForm(['email' => $login, 'password' => $password])
            ->call('authenticate');
    }

    private function user(string $role, string $username): User
    {
        return User::query()->create([
            'name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@example.test",
            'password' => 'password', 'role' => $role, 'is_active' => true,
        ]);
    }
}
