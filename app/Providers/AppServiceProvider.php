<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\Purchase;
use Laravel\Sanctum\Sanctum;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

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
    }
}
