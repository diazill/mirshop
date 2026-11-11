<?php

namespace App\Filament\Actions;

use App\Enums\AdjustmentReason;
use App\Exceptions\StockException;
use App\Filament\Resources\InventoryMovementResource;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\StockService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/** Aksi stok di tabel produk. Semua perubahan lewat StockService. */
class StockActions
{
    public static function adjust(): Action
    {
        return Action::make('adjustStock')
            ->label('Koreksi stok')
            ->icon('heroicon-o-adjustments-horizontal')
            ->modalHeading(fn (Product $record): string => "Koreksi stok: {$record->title}")
            ->modalDescription(fn (Product $record): string => 'Stok sistem saat ini: ' . static::stockLabel($record))
            ->hidden(fn (Product $record): bool => $record->trashed())
            ->form([
                Forms\Components\Radio::make('direction')
                    ->label('Jenis koreksi')
                    ->options(['in' => 'Tambah stok', 'out' => 'Kurangi stok'])
                    ->default('out')
                    ->inline()
                    ->required(),
                Forms\Components\Select::make('product_unit_id')
                    ->label('Satuan')
                    ->options(fn (Product $record) => static::unitOptions($record))
                    ->default(fn (Product $record) => $record->baseUnit?->id)
                    ->required(),
                Forms\Components\TextInput::make('qty')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(0.001)
                    ->required(),
                Forms\Components\Select::make('reason')
                    ->label('Alasan')
                    ->options(AdjustmentReason::options())
                    ->live()
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->label('Catatan')
                    ->required(fn (Get $get): bool => $get('reason') === AdjustmentReason::Other->value)
                    ->maxLength(1000),
                Forms\Components\Toggle::make('allow_negative')
                    ->label('Izinkan stok minus')
                    ->helperText('Hanya owner. Gunakan bila barang fisik ada tetapi belum tercatat.')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('owner')),
            ])
            ->action(function (Product $record, array $data, Action $action): void {
                $qty = ($data['direction'] === 'out' ? '-' : '') . $data['qty'];

                static::run($action, fn () => app(StockService::class)->adjust(
                    product: $record,
                    qty: $qty,
                    reason: AdjustmentReason::from($data['reason']),
                    unit: static::findUnit($record, $data['product_unit_id']),
                    notes: $data['notes'] ?? null,
                    allowNegative: ($data['allow_negative'] ?? false) && auth()->user()?->hasRole('owner'),
                ), $record);
            });
    }

    public static function opname(): Action
    {
        return Action::make('opnameStock')
            ->label('Opname')
            ->icon('heroicon-o-clipboard-document-check')
            ->modalHeading(fn (Product $record): string => "Opname: {$record->title}")
            ->modalDescription(fn (Product $record): string => 'Stok sistem saat ini: ' . static::stockLabel($record)
                . '. Masukkan hasil hitung fisik; selisihnya dicatat otomatis.')
            ->hidden(fn (Product $record): bool => $record->trashed())
            ->form([
                Forms\Components\TextInput::make('counted')
                    ->label('Hasil hitung fisik')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(fn (Product $record): ?string => $record->baseUnit?->unit?->name)
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->label('Catatan')
                    ->maxLength(1000),
            ])
            ->action(function (Product $record, array $data, Action $action): void {
                static::run($action, fn () => app(StockService::class)->opname(
                    product: $record,
                    countedBaseQty: $data['counted'],
                    notes: $data['notes'] ?? null,
                ), $record);
            });
    }

    public static function history(): Action
    {
        return Action::make('stockHistory')
            ->label('Riwayat stok')
            ->icon('heroicon-o-clock')
            ->url(fn (Product $record): string => InventoryMovementResource::getUrl('index', [
                'tableFilters' => ['product_id' => ['value' => $record->id]],
            ]));
    }

    private static function run(Action $action, callable $callback, Product $record): void
    {
        try {
            $callback();
        } catch (StockException $exception) {
            Notification::make()->danger()->title('Stok tidak diubah')->body($exception->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title('Stok diperbarui')
            ->body('Stok sekarang: ' . static::stockLabel($record->refresh()))
            ->send();
    }

    private static function unitOptions(Product $record): array
    {
        return $record->units()->with('unit')->get()
            ->mapWithKeys(fn (ProductUnit $unit): array => [
                $unit->id => $unit->is_base
                    ? $unit->unit->name
                    : $unit->unit->name . ' (' . Product::formatQty($unit->conversion_qty) . ' ' . $record->baseUnit?->unit?->name . ')',
            ])
            ->all();
    }

    private static function findUnit(Product $record, int|string|null $id): ?ProductUnit
    {
        return $id ? $record->units()->with('unit')->whereKey($id)->first() : null;
    }

    private static function stockLabel(Product $record): string
    {
        return trim(Product::formatQty($record->current_stock) . ' ' . ($record->baseUnit?->unit?->name ?? ''));
    }
}
