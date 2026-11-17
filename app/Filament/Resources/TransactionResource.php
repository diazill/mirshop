<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Exceptions\BusinessException;
use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\TransactionService;
use App\Support\Money;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Daftar penjualan (hanya baca). Penjualan dibuat dari aplikasi kasir lewat API. */
class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Penjualan';
    protected static ?string $modelLabel = 'Penjualan';
    protected static ?string $pluralModelLabel = 'Penjualan';
    protected static ?string $slug = 'penjualan';
    protected static ?string $recordTitleAttribute = 'invoice_no';

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('device'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('invoice_no')->label('No. nota')->searchable(),
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->description(fn (Transaction $record): ?string => $record->source === 'offline'
                        ? 'offline ' . ($record->device?->code ?? '') . ', sinkron ' . $record->created_at->format('d M H:i')
                        : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('cashier_name')->label('Kasir')->searchable(),
                Tables\Columns\TextColumn::make('transaction_items_count')->label('Item')->counts('transactionItems'),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => Money::rupiah($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Bayar')
                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TransactionStatus $state): string => $state->label())
                    ->color(fn (TransactionStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('review_flags')
                    ->label('Tinjau')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Transaction $record): array => static::reviewFlags($record)),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('needs_review')->label('Perlu ditinjau'),
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(TransactionStatus::options()),
                Tables\Filters\SelectFilter::make('payment_method')->label('Bayar')->options(PaymentMethod::options()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::reviewAction(Tables\Actions\Action::class),
                static::cancelAction(Tables\Actions\Action::class),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('invoice_no')->label('No. nota'),
                Infolists\Components\TextEntry::make('occurred_at')->label('Waktu')->dateTime('d M Y H:i'),
                Infolists\Components\TextEntry::make('source')
                    ->label('Asal')
                    ->formatStateUsing(fn (string $state, Transaction $record): string => $state === 'offline'
                        ? "Offline dari {$record->device?->code}, sinkron {$record->created_at->format('d M Y H:i')}"
                        : 'Online'),
                Infolists\Components\TextEntry::make('review_flags')
                    ->label('Perlu ditinjau')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Transaction $record): array => static::reviewFlags($record))
                    ->visible(fn (Transaction $record): bool => $record->needs_review),
                Infolists\Components\TextEntry::make('cashier_name')->label('Kasir'),
                Infolists\Components\TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TransactionStatus $state): string => $state->label())
                    ->color(fn (TransactionStatus $state): string => $state->color()),
                Infolists\Components\TextEntry::make('payment_method')
                    ->label('Bayar')
                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),
                Infolists\Components\TextEntry::make('total_amount')->label('Total')->formatStateUsing(fn ($state) => Money::rupiah($state)),
                Infolists\Components\TextEntry::make('paid_amount')->label('Dibayar')->formatStateUsing(fn ($state) => Money::rupiah($state)),
                Infolists\Components\TextEntry::make('change_amount')->label('Kembalian')->formatStateUsing(fn ($state) => Money::rupiah($state)),
                Infolists\Components\TextEntry::make('notes')->label('Catatan')->columnSpanFull()->placeholder('-'),
                Infolists\Components\TextEntry::make('cancel_reason')
                    ->label('Alasan batal')
                    ->columnSpanFull()
                    ->visible(fn (Transaction $record): bool => $record->status === TransactionStatus::Cancelled),
            ]),
            Infolists\Components\RepeatableEntry::make('transactionItems')
                ->label('Barang')
                ->columns(5)
                ->schema([
                    Infolists\Components\TextEntry::make('product.title')->label('Produk')->columnSpan(2),
                    Infolists\Components\TextEntry::make('qty')
                        ->label('Jumlah')
                        ->formatStateUsing(fn ($state, $record): string => Product::formatQty($state) . ' ' . $record->unit_name),
                    Infolists\Components\TextEntry::make('price_at_time')
                        ->label('Harga')
                        ->formatStateUsing(fn ($state, $record): string => Money::rupiah($state) . ($record->price_type !== 'eceran' ? " ({$record->price_type})" : ''))
                        ->helperText(fn ($record): ?string => $record->official_price !== null && $record->official_price !== $record->price_at_time
                            ? 'Harga resmi ' . Money::rupiah($record->official_price)
                            : null),
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->formatStateUsing(fn ($state) => Money::rupiah($state)),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<int, string> */
    public static function reviewFlags(Transaction $record): array
    {
        if (! $record->needs_review) {
            return [];
        }

        return array_values(array_filter([
            $record->below_cost ? 'Di bawah HPP' : null,
            $record->price_mismatch ? 'Harga beda' : null,
            $record->stock_conflict ? 'Stok minus' : null,
            $record->late_sync ? 'Sinkron terlambat' : null,
        ]));
    }

    /**
     * Tandai nota offline sudah diperiksa owner/admin.
     *
     * @param  class-string<Tables\Actions\Action|\Filament\Actions\Action>  $class
     */
    public static function reviewAction(string $class)
    {
        return $class::make('markReviewed')
            ->label('Sudah ditinjau')
            ->icon('heroicon-o-check-badge')
            ->color('warning')
            ->visible(fn (Transaction $record): bool => $record->needs_review
                && (bool) auth()->user()?->hasRole('owner', 'admin'))
            ->requiresConfirmation()
            ->modalDescription('Pastikan harga atau stok sudah dicek dan dikoreksi bila perlu (Koreksi stok di menu Produk).')
            ->action(function (Transaction $record): void {
                app(TransactionService::class)->markReviewed($record, auth()->user());
                Notification::make()->success()->title('Ditandai sudah ditinjau.')->send();
            });
    }

    /**
     * @param  class-string<Tables\Actions\Action|\Filament\Actions\Action>  $class
     */
    public static function cancelAction(string $class)
    {
        return $class::make('cancel')
            ->label('Batalkan')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Transaction $record): bool => $record->status === TransactionStatus::Paid
                && (bool) auth()->user()?->hasRole('owner'))
            ->modalHeading(fn (Transaction $record): string => "Batalkan {$record->invoice_no}?")
            ->modalDescription('Stok semua barang di nota ini akan dikembalikan.')
            ->form([
                Forms\Components\Textarea::make('reason')->label('Alasan pembatalan')->required()->maxLength(1000),
            ])
            ->modalSubmitActionLabel('Batalkan penjualan')
            ->action(function (Transaction $record, array $data, $action): void {
                try {
                    app(TransactionService::class)->cancel($record, $data['reason'], auth()->user());
                } catch (BusinessException $exception) {
                    Notification::make()->danger()->title('Gagal')->body($exception->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Penjualan dibatalkan, stok dikembalikan.')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'view' => Pages\ViewTransaction::route('/{record}'),
        ];
    }
}
