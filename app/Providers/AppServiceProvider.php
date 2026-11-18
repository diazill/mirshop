<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        // Semua endpoint memakai Bearer token kecuali yang ditandai @unauthenticated.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }
}
