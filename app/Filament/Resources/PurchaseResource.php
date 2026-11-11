<?php

namespace App\Filament\Resources;

use App\Enums\PurchaseStatus;
use App\Filament\Actions\PurchaseActions;
use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Support\Money;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Stok';
    protected static ?string $modelLabel = 'Pembelian';
    protected static ?string $pluralModelLabel = 'Pembelian';
    protected static ?string $slug = 'pembelian';
    protected static ?string $recordTitleAttribute = 'reference_no';
    protected static ?int $navigationSort = 1;

    /** Hanya draft yang bisa diedit. */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Purchase && $record->isDraft();
    }

    /** Pembelian tidak dihapus permanen; gunakan Batalkan. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Info pembelian')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('reference_no')
                            ->label('No. pembelian')
                            ->placeholder('Otomatis')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Select::make('supplier_id')
                            ->label('Pemasok')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
                                Forms\Components\TextInput::make('contact_person')->label('Kontak')->required()->maxLength(255),
                                Forms\Components\TextInput::make('phone')->label('Telepon')->tel()->required()->maxLength(255),
                            ]),
                        Forms\Components\DatePicker::make('purchase_date')
                            ->label('Tanggal')
                            ->default(now())
                            ->required(),
                        Forms\Components\TextInput::make('supplier_invoice_no')
                            ->label('No. faktur pemasok')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->columnSpan(2)
                            ->rows(2),
                    ]),

                Forms\Components\Section::make('Status')
                    ->columns(3)
                    ->visibleOn('view')
                    ->schema([
                        Forms\Components\Placeholder::make('status_label')
                            ->label('Status')
                            ->content(fn (?Purchase $record): ?string => $record?->status->label()),
                        Forms\Components\Placeholder::make('received_info')
                            ->label('Diterima')
                            ->visible(fn (?Purchase $record): bool => (bool) $record?->received_at)
                            ->content(fn (?Purchase $record): string => $record->received_at->format('d M Y H:i')
                                . ($record->receiver ? ' oleh ' . $record->receiver->name : '')),
                        Forms\Components\Placeholder::make('cancel_info')
                            ->label('Dibatalkan')
                            ->visible(fn (?Purchase $record): bool => (bool) $record?->cancelled_at)
                            ->content(fn (?Purchase $record): string => $record->cancelled_at->format('d M Y H:i')
                                . ($record->canceller ? ' oleh ' . $record->canceller->name : '')
                                . ' — ' . $record->cancel_reason),
                    ]),

                Forms\Components\Section::make('Barang')
                    ->schema([
                        static::itemsRepeater(),
                        Forms\Components\Placeholder::make('grand_total')
                            ->label('Total')
                            ->content(fn (Get $get): string => Money::rupiah(
                                collect($get('purchaseItems') ?? [])->sum(fn (array $item): float => static::lineTotal($item))
                            )),
                    ]),
            ]);
    }

    protected static function itemsRepeater(): Repeater
    {
        return Repeater::make('purchaseItems')
            ->relationship()
            ->hiddenLabel()
            ->columns(12)
            ->minItems(1)
            ->addActionLabel('Tambah barang')
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Produk')
                    ->options(fn () => Product::query()->orderBy('title')->pluck('title', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $unit = $state ? static::purchasableUnits($state)->sortByDesc('conversion_qty')->first() : null;
                        $set('product_unit_id', $unit?->id);
                        $set('buy_price', $unit ? static::suggestedPrice($unit) : null);
                    })
                    ->columnSpan(4),
                Forms\Components\Select::make('product_unit_id')
                    ->label('Satuan')
                    ->options(fn (Get $get) => $get('product_id')
                        ? static::purchasableUnits($get('product_id'))->mapWithKeys(fn (ProductUnit $unit): array => [
                            $unit->id => $unit->is_base
                                ? $unit->unit->name
                                : $unit->unit->name . ' (' . Product::formatQty($unit->conversion_qty) . ')',
                        ])
                        : [])
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $unit = $state ? ProductUnit::query()->with('product')->find($state) : null;
                        $set('buy_price', $unit ? static::suggestedPrice($unit) : null);
                    })
                    ->columnSpan(2),
                Forms\Components\TextInput::make('qty')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(0.001)
                    ->required()
                    ->live(onBlur: true)
                    ->rules([fn (Get $get): Closure => static::validateQtyForUnit($get('product_unit_id'))])
                    ->columnSpan(2),
                Forms\Components\TextInput::make('buy_price')
                    ->label('Harga beli / satuan')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->prefix('Rp')
                    ->required()
                    ->live(onBlur: true)
                    ->columnSpan(2),
                Forms\Components\Placeholder::make('line_total')
                    ->label('Subtotal')
                    ->content(fn (Get $get): string => Money::rupiah(static::lineTotal([
                        'qty' => $get('qty'),
                        'buy_price' => $get('buy_price'),
                    ])))
                    ->columnSpan(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reference_no')
                    ->label('No. pembelian')
                    ->searchable(),
                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Pemasok')
                    ->searchable(),
                Tables\Columns\TextColumn::make('supplier_invoice_no')
                    ->label('Faktur pemasok')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('purchase_items_count')
                    ->label('Item')
                    ->counts('purchaseItems'),
                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => Money::rupiah($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseStatus $state): string => $state->label())
                    ->color(fn (PurchaseStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Diterima')
                    ->dateTime('d M Y H:i')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(PurchaseStatus::options()),
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Pemasok')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                PurchaseActions::receive(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                PurchaseActions::cancel(),
            ])
            ->bulkActions([]);
    }

    /** Satuan produk yang boleh dibeli. */
    protected static function purchasableUnits(int|string $productId)
    {
        return ProductUnit::query()
            ->with(['unit', 'product'])
            ->where('product_id', $productId)
            ->where('is_purchasable', true)
            ->orderBy('conversion_qty')
            ->get();
    }

    /** Saran harga beli = HPP per satuan dasar × isi satuan. */
    protected static function suggestedPrice(ProductUnit $unit): int
    {
        return (int) round((int) $unit->product?->cost_price * (float) $unit->conversion_qty);
    }

    protected static function lineTotal(array $item): float
    {
        return round((float) ($item['qty'] ?? 0) * (float) ($item['buy_price'] ?? 0));
    }

    protected static function validateQtyForUnit(int|string|null $productUnitId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($productUnitId): void {
            $unit = $productUnitId ? ProductUnit::query()->with('unit')->find($productUnitId) : null;

            if ($unit && ! $unit->allow_decimal_qty && floor((float) $value) != (float) $value) {
                $fail("Satuan {$unit->unit->name} tidak boleh pecahan.");
            }
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'create' => Pages\CreatePurchase::route('/create'),
            'view' => Pages\ViewPurchase::route('/{record}'),
            'edit' => Pages\EditPurchase::route('/{record}/edit'),
        ];
    }
}
