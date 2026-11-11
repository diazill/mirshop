<?php

namespace App\Filament\Actions;

use App\Enums\PurchaseStatus;
use App\Exceptions\BusinessException;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Support\Money;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;

/**
 * Aksi Terima barang & Batalkan untuk tabel dan halaman pembelian.
 * $class: Filament\Tables\Actions\Action atau Filament\Actions\Action.
 */
class PurchaseActions
{
    /** @param class-string<TableAction|PageAction> $class */
    public static function receive(string $class = TableAction::class): TableAction|PageAction
    {
        return $class::make('receive')
            ->label('Terima barang')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('success')
            ->visible(fn (Purchase $record): bool => $record->isDraft())
            ->requiresConfirmation()
            ->modalHeading(fn (Purchase $record): string => "Terima barang {$record->reference_no}?")
            ->modalDescription(fn (Purchase $record): string => sprintf(
                'Stok %d item akan bertambah dan HPP produk diperbarui (total %s). Setelah diterima, pembelian tidak bisa diedit.',
                $record->purchaseItems()->count(),
                Money::rupiah($record->total_cost),
            ))
            ->modalSubmitActionLabel('Ya, terima')
            ->action(function (Purchase $record, $action): void {
                static::run($action, fn () => app(PurchaseService::class)->receive($record), 'Barang diterima, stok sudah bertambah.');
            });
    }

    /** @param class-string<TableAction|PageAction> $class */
    public static function cancel(string $class = TableAction::class): TableAction|PageAction
    {
        return $class::make('cancel')
            ->label('Batalkan')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Purchase $record): bool => $record->isDraft()
                || ($record->status === PurchaseStatus::Received && (bool) auth()->user()?->hasRole('owner')))
            ->modalHeading(fn (Purchase $record): string => "Batalkan {$record->reference_no}?")
            ->modalDescription(fn (Purchase $record): string => $record->status === PurchaseStatus::Received
                ? 'Barang sudah diterima: stok semua item akan ditarik kembali. Gagal bila sebagian barang sudah terjual. HPP produk tidak dikembalikan otomatis.'
                : 'Pembelian draft akan ditandai batal.')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Alasan pembatalan')
                    ->required()
                    ->maxLength(1000),
            ])
            ->modalSubmitActionLabel('Batalkan pembelian')
            ->action(function (Purchase $record, array $data, $action): void {
                static::run($action, fn () => app(PurchaseService::class)->cancel($record, $data['reason']), 'Pembelian dibatalkan.');
            });
    }

    private static function run($action, callable $callback, string $successMessage): void
    {
        try {
            $callback();
        } catch (BusinessException $exception) {
            Notification::make()->danger()->title('Gagal')->body($exception->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title($successMessage)->send();
    }
}
