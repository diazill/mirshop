<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dokumentasi API (/docs/api) hanya untuk owner/admin yang login di panel admin. */
class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_staff_without_admin_role_cannot_open_the_docs(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();

        $this->actingAs($this->user('kasir'));
        $this->get('/docs/api')->assertForbidden();

        $this->actingAs($this->user('owner', active: false));
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_admin_sees_every_ready_endpoint_grouped(): void
    {
        $this->actingAs($this->user('admin'));

        $this->get('/docs/api')->assertOk()->assertSee('Dokumentasi API MirShop');

        $spec = $this->get('/docs/api.json')->assertOk()->json();

        $this->assertSame(
            ['Login', 'Katalog publik', 'Kasir (POS)', 'Sinkron HP offline'],
            array_column($spec['tags'], 'name'),
        );
        $this->assertArrayHasKey('/v1/pos/transactions', $spec['paths']);
        $this->assertArrayHasKey('/v1/sync/transactions', $spec['paths']);
        $this->assertSame([], $spec['paths']['/v1/products']['get']['security']);
        $this->assertContains(
            'Idempotency-Key',
            array_column($spec['paths']['/v1/pos/transactions']['post']['parameters'], 'name'),
        );
    }

    public function test_dashboard_menu_links_to_the_docs_for_owner_only(): void
    {
        $this->actingAs($this->user('owner'));
        $this->get('/admin')->assertOk()->assertSee('Dokumentasi API')->assertSee('/docs/api', false);
    }

    private function user(string $role, bool $active = true): User
    {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => ucfirst($role) . " {$n}", 'email' => "{$role}{$n}@example.test",
            'password' => 'password', 'role' => $role, 'is_active' => $active,
        ]);
    }
}
