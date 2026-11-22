<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Alias pendek untuk inventory_movements.reference_type (tidak di-enforce agar
        // morph lain seperti token Sanctum tetap memakai nama class).
        Relation::morphMap([
            'purchase' => Purchase::class,
            'transaction' => Transaction::class,
        ]);

        // Dokumentasi API (/docs/api): hanya owner dan admin yang login di panel admin.
        Gate::define('viewApiDocs', fn (?User $user): bool => (bool) $user?->is_active && $user->hasRole('owner', 'admin'));

        // Katalog publik: 120/menit per IP. Server toko online (Next.js) yang mengirim header
        // X-Storefront-Key yang benar mendapat batas sendiri yang lebih longgar, supaya build
        // (prerender semua halaman produk) tidak kena 429. Kunci hanya disimpan di env kedua server.
        RateLimiter::for('public-api', function (Request $request): Limit {
            $key = (string) config('services.storefront.key');
            $sent = (string) $request->header('X-Storefront-Key');

            if ($key !== '' && $sent !== '' && hash_equals($key, $sent)) {
                return Limit::perMinute((int) config('services.storefront.per_minute'))->by('storefront');
            }

            return Limit::perMinute(120)->by($request->ip());
        });

        // Semua endpoint memakai Bearer token kecuali yang ditandai @unauthenticated.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }
}
