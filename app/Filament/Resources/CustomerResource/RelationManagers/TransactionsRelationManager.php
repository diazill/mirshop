<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Riwayat nota per pelanggan (hanya baca). */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Riwayat nota';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('invoice_no')
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('invoice_no')->label('No. nota')->searchable(),
                Tables\Columns\TextColumn::make('occurred_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('cashier_name')->label('Kasir'),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => Money::rupiah($state)),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Bayar')
                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TransactionStatus $state): string => $state->label())
                    ->color(fn (TransactionStatus $state): string => $state->color()),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Lihat')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Transaction $record): string => TransactionResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
