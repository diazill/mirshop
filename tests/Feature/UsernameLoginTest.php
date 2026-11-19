<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::query()->create([
            'name' => 'Pemilik', 'username' => '  Pemilik.Toko ', 'email' => 'owner@example.test',
            'password' => 'rahasia123', 'role' => 'owner', 'is_active' => true,
        ]);
    }

    public function test_username_is_stored_lowercase_and_trimmed(): void
    {
        $this->assertSame('pemilik.toko', $this->owner->refresh()->username);
    }

    public function test_api_login_accepts_username_in_any_case_or_email(): void
    {
        foreach (['pemilik.toko', 'PEMILIK.Toko', 'Owner@Example.test'] as $login) {
            $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'rahasia123', 'device_name' => 'hp'])
                ->assertOk()
                ->assertJsonPath('user.username', 'pemilik.toko');
        }

        // Aplikasi lama yang masih mengirim `email` tetap jalan.
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'rahasia123', 'device_name' => 'hp'])
            ->assertOk();

    }

    public function test_api_login_rejects_wrong_password_and_missing_login(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'pemilik.toko', 'password' => 'salah', 'device_name' => 'hp'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'KREDENSIAL_TIDAK_VALID');

        $this->postJson('/api/v1/auth/login', ['password' => 'rahasia123', 'device_name' => 'hp'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDASI_GAGAL');
    }

    public function test_admin_panel_login_with_username(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => 'Pemilik.Toko', 'password' => 'rahasia123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_admin_panel_login_fails_with_wrong_username(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => 'tidak.ada', 'password' => 'rahasia123'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_owner_creates_cashier_with_username_who_can_use_the_cashier_api(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Kasir Depan', 'username' => 'Kasir01', 'email' => 'kasir@example.test',
                'password' => 'Rahasia-123', 'role' => 'kasir', 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cashier = User::query()->where('email', 'kasir@example.test')->firstOrFail();
        $this->assertSame('kasir01', $cashier->username);
        $this->assertSame('kasir', $cashier->role);

        // Regresi: dulu form menyimpan "cashier" sehingga API kasir menolak dengan AKSES_DITOLAK.
        $token = $this->postJson('/api/v1/auth/login', ['login' => 'kasir01', 'password' => 'Rahasia-123', 'device_name' => 'hp'])
            ->assertOk()
            ->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/pos/transactions')->assertOk();
    }

    public function test_username_must_be_unique_ignoring_case_and_without_spaces(): void
    {
        $this->actingAs($this->owner);

        $base = ['name' => 'X', 'email' => 'x@example.test', 'password' => 'Rahasia-123', 'role' => 'kasir', 'is_active' => true];

        Livewire::test(CreateUser::class)
            ->fillForm($base + ['username' => 'PEMILIK.TOKO'])
            ->call('create')
            ->assertHasFormErrors(['username']);

        $this->assertSame(1, User::query()->count());

        Livewire::test(CreateUser::class)
            ->fillForm($base + ['username' => 'kasir satu'])
            ->call('create')
            ->assertHasFormErrors(['username' => 'regex']);
    }

    public function test_assign_role_command_uses_the_same_role_values(): void
    {
        $this->artisan('users:assign-role', ['email' => 'owner@example.test', 'role' => 'cashier'])->assertFailed();
        $this->artisan('users:assign-role', ['email' => 'owner@example.test', 'role' => 'kasir'])->assertSuccessful();
    }
}
