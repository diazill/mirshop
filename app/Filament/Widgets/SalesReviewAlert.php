<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Peringatan di dashboard owner/admin: nota offline yang perlu dicek.
 * "Di bawah HPP" paling penting: bisa berarti kasir mencatat harga lebih rendah dari uang yang diterima.
 */
class SalesReviewAlert extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected ?string $heading = 'Penjualan perlu ditinjau';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasRole('owner', 'admin');
    }

    protected function getStats(): array
    {
        $pending = Transaction::query()->where('needs_review', true);

        $url = TransactionResource::getUrl('index', [
            'tableFilters' => ['needs_review' => ['value' => 1]],
        ]);

        $belowCost = (clone $pending)->where('below_cost', true)->count();

        return [
            Stat::make('Di bawah HPP', $belowCost)
                ->description($belowCost ? 'Cek kasir dan uang yang diterima' : 'Aman')
                ->color($belowCost ? 'danger' : 'success')
                ->url($url),
            Stat::make('Harga beda', (clone $pending)->where('price_mismatch', true)->count())
                ->description('Harga kasir beda dari harga resmi')
                ->color('warning')
                ->url($url),
            Stat::make('Stok minus', (clone $pending)->where('stock_conflict', true)->count())
                ->description('Koreksi stok di menu Produk')
                ->color('warning')
                ->url($url),
            Stat::make('Sinkron terlambat', (clone $pending)->where('late_sync', true)->count())
                ->description('Nota lebih dari ' . \App\Services\TransactionService::OFFLINE_LATE_HOURS . ' jam sebelum dikirim')
                ->color('gray')
                ->url($url),
        ];
    }
}
