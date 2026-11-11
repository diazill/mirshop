<?php

namespace App\Filament\Resources\SupplierResource\RelationManagers;

use App\Enums\PurchaseStatus;
use App\Filament\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Riwayat pembelian per pemasok (hanya baca). */
class PurchasesRelationManager extends RelationManager
{
    protected static string $relationship = 'purchases';

    protected static ?string $title = 'Riwayat pembelian';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference_no')
            ->defaultSort('purchase_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reference_no')->label('No. pembelian')->searchable(),
                Tables\Columns\TextColumn::make('purchase_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('supplier_invoice_no')->label('Faktur pemasok'),
                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => Money::rupiah($state)),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseStatus $state): string => $state->label())
                    ->color(fn (PurchaseStatus $state): string => $state->color()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(PurchaseStatus::options()),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Lihat')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Purchase $record): string => PurchaseResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
