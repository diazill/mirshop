<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_view_their_profile_and_revoke_the_token(): void
    {
        $user = User::query()->create([
            'name' => 'Admin Test',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);

        $login->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonMissingPath('user.password');

        $token = $login->json('access_token');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TIDAK_TERAUTENTIKASI');
    }

    public function test_inactive_user_cannot_receive_an_api_token(): void
    {
        $user = User::query()->create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.test',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'AKUN_NONAKTIF');
    }

    public function test_login_validation_uses_the_api_error_shape(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDASI_GAGAL')
            ->assertJsonPath('message', 'Data yang dikirim tidak valid.')
            ->assertJsonPath('errors.email.0', 'Email wajib diisi.');
    }
}
