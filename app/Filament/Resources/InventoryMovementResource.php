<?php

namespace App\Filament\Resources;

use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Filament\Resources\InventoryMovementResource\Pages;
use App\Models\InventoryMovement;
use App\Models\Product;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Riwayat mutasi stok: hanya baca. Perubahan stok lewat StockService. */
class InventoryMovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup = 'Stok';
    protected static ?string $modelLabel = 'Mutasi Stok';
    protected static ?string $pluralModelLabel = 'Riwayat Stok';
    protected static ?string $slug = 'riwayat-stok';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.baseUnit.unit', 'user']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.title')
                    ->label('Produk')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('movement_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (MovementType $state): string => $state->label())
                    ->color(fn (MovementType $state): string => $state->color()),
                Tables\Columns\TextColumn::make('qty')
                    ->label('Input')
                    ->formatStateUsing(fn ($state, InventoryMovement $record): string => trim(
                        Product::formatQty($state) . ' ' . $record->unit_name
                    )),
                Tables\Columns\TextColumn::make('qty_change')
                    ->label('Perubahan')
                    ->formatStateUsing(fn ($state, InventoryMovement $record): string => trim(
                        ((float) $state > 0 ? '+' : '') . Product::formatQty($state) . ' ' . static::baseUnitName($record)
                    ))
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('stock_after')
                    ->label('Stok akhir')
                    ->formatStateUsing(fn ($state, InventoryMovement $record): string => trim(
                        Product::formatQty($state) . ' ' . static::baseUnitName($record)
                    )),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? AdjustmentReason::tryFrom($state)?->label() ?? $state : null)
                    ->description(fn (InventoryMovement $record): ?string => $record->notes)
                    ->wrap(),
                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Dokumen')
                    ->formatStateUsing(fn (?string $state, InventoryMovement $record): ?string => $state ? ucfirst($state) . ' #' . $record->reference_id : null)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Oleh')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Produk')
                    ->relationship('product', 'title')
                    ->searchable(),
                Tables\Filters\SelectFilter::make('movement_type')
                    ->label('Jenis')
                    ->options(MovementType::options()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    private static function baseUnitName(InventoryMovement $record): string
    {
        return $record->product?->baseUnit?->unit?->name ?? '';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryMovements::route('/'),
        ];
    }
}
