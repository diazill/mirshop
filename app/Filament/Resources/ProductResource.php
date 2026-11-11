<?php

namespace App\Filament\Resources;

use App\Filament\Actions\StockActions;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Unit;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Master Data';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('sku')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Nomor unik identitas produk (Stock Keeping Unit). Kosongkan jika ingin dibuat otomatis.'),
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Nama lengkap produk yang akan ditampilkan ke pelanggan.'),
                Forms\Components\Select::make('category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Kategori utama produk untuk memudahkan pengelompokan.'),
                Forms\Components\Select::make('brand_id')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Merek dari produk (opsional).'),
                Forms\Components\TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('Rp')
                    ->helperText('Harga jual eceran per 1 satuan dasar (mis. per Kg). Harga satuan lain diatur di bagian Satuan & Harga.'),
                Forms\Components\TextInput::make('cost_price')
                    ->required()
                    ->numeric()
                    ->prefix('Rp')
                    ->helperText('Harga beli/modal (HPP) per 1 satuan dasar untuk keperluan laporan.'),
                Forms\Components\TextInput::make('weight_gram')
                    ->required()
                    ->numeric()
                    ->suffix('g')
                    ->helperText('Berat fisik produk dalam hitungan gram untuk ongkos kirim.'),
                Forms\Components\Repeater::make('images')
                    ->relationship('images')
                    ->schema([
                        Forms\Components\FileUpload::make('image_url')
                            ->image()
                            ->disk('s3')
                            ->directory(fn () => 'katalog-produk/' . date('Y/m'))
                            ->getUploadedFileNameForStorageUsing(
                                fn (\Illuminate\Http\UploadedFile $file): string => (string) str(uniqid() . '-' . $file->getClientOriginalName())
                            )
                            ->optimize('webp')
                            ->columnSpanFull()
                            ->label('Product Image'),
                        Forms\Components\Toggle::make('is_primary')
                            ->label('Primary Image')
                            ->default(false)
                            ->helperText('Jadikan gambar ini sebagai sampul utama produk.'),
                    ])
                    ->orderColumn('sort_order')
                    ->deleteAction(
                        fn ($action) => $action->requiresConfirmation()
                    )
                    ->columnSpanFull()
                    ->label('Product Images')
                    ->helperText('Unggah foto produk. Anda bisa mengatur urutan tampilan dengan menyeret ikon panah atas-bawah.'),
                Forms\Components\Textarea::make('blurb')
                    ->maxLength(65535)
                    ->columnSpanFull()
                    ->helperText('Deskripsi singkat atau penjelasan detail tentang produk.'),
                static::unitsSection(),
                Forms\Components\TextInput::make('current_stock')
                    ->label('Stok saat ini')
                    ->numeric()
                    ->default(0)
                    ->disabled()
                    ->dehydrated(false)
                    ->suffix(fn (?Product $record): ?string => $record?->baseUnit?->unit?->name)
                    ->helperText('Dalam satuan dasar. Stok dihitung otomatis oleh sistem mutasi (Inventory Movements).'),
                Forms\Components\TextInput::make('min_stock')
                    ->label('Stok minimum')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(fn (?Product $record): ?string => $record?->baseUnit?->unit?->name)
                    ->helperText('Dalam satuan dasar. Produk ditandai stok menipis bila stok <= nilai ini. Kosongkan bila tidak perlu.'),
                Forms\Components\Toggle::make('is_best_product')
                    ->required()
                    ->helperText('Tandai jika ini adalah produk unggulan/terlaris untuk ditonjolkan.'),
                Forms\Components\Toggle::make('is_active')
                    ->required()
                    ->default(true)
                    ->helperText('Tandai untuk menampilkan produk ini di sistem POS dan toko. Jika dimatikan, produk disembunyikan.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('baseUnit.unit'))
            ->columns([
                Tables\Columns\ImageColumn::make('images.image_url')
                    ->label('Image')
                    ->disk('s3')
                    ->stacked()
                    ->square(),
                Tables\Columns\TextColumn::make('sku')
                    ->searchable(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->numeric()
                    ->prefix('Rp ')
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok')
                    ->formatStateUsing(fn ($state, Product $record): string => trim(
                        Product::formatQty($state) . ' ' . ($record->baseUnit?->unit?->name ?? '')
                    ))
                    ->color(fn (Product $record): ?string => $record->isLowStock() ? 'danger' : null)
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_best_product')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->relationship('category', 'name'),
                Tables\Filters\SelectFilter::make('brand_id')
                    ->relationship('brand', 'name'),
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    StockActions::adjust(),
                    StockActions::opname(),
                    StockActions::history(),
                ])
                    ->label('Stok')
                    ->icon('heroicon-o-archive-box')
                    ->button()
                    ->outlined(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
                // Produk dengan riwayat stok hanya boleh di-soft delete (audit tetap utuh).
                Tables\Actions\ForceDeleteAction::make()
                    ->hidden(fn (Product $record): bool => ! $record->trashed() || $record->hasStockHistory()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function unitsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Satuan & Harga')
            ->description('Stok selalu dicatat dalam satuan dasar. Satuan lain dikonversi ke satuan dasar, mis. 1 Karung = 50 Kg.')
            ->schema([
                Repeater::make('units')
                    ->relationship()
                    ->hiddenLabel()
                    ->schema([
                        Forms\Components\Select::make('unit_id')
                            ->label('Satuan')
                            ->options(fn (Get $get) => Unit::query()
                                ->where('is_active', true)
                                ->when($get('unit_id'), fn ($query, $id) => $query->orWhere('id', $id))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->required()
                            ->live()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state) {
                                    $set('allow_decimal_qty', (bool) Unit::query()->whereKey($state)->value('allow_decimal_qty'));
                                }
                            })
                            ->disabled(fn (Get $get, $livewire): bool => $get('is_base') && static::isStockLocked($livewire)),
                        Forms\Components\Toggle::make('is_base')
                            ->label('Satuan dasar')
                            ->live()
                            ->inline(false)
                            ->disabled(fn ($livewire): bool => static::isStockLocked($livewire))
                            ->helperText(fn ($livewire): ?string => static::isStockLocked($livewire)
                                ? 'Terkunci karena produk sudah punya riwayat stok.'
                                : null),
                        Forms\Components\TextInput::make('conversion_qty')
                            ->label('Isi (dalam satuan dasar)')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->minValue(0.001)
                            ->disabled(fn (Get $get): bool => (bool) $get('is_base'))
                            ->helperText('Mis. Karung isi 50 → tulis 50.'),
                        Forms\Components\TextInput::make('barcode')
                            ->label('Barcode')
                            ->maxLength(255)
                            ->unique('product_units', 'barcode', ignoreRecord: true),
                        Forms\Components\Toggle::make('allow_decimal_qty')
                            ->label('Boleh pecahan')
                            ->inline(false)
                            ->helperText('Mis. jual 0,5 kg.'),
                        Forms\Components\Toggle::make('is_sellable')
                            ->label('Bisa dijual')
                            ->default(true)
                            ->inline(false),
                        Forms\Components\Toggle::make('is_purchasable')
                            ->label('Bisa dibeli')
                            ->default(true)
                            ->inline(false),
                        Repeater::make('prices')
                            ->relationship()
                            ->label('Harga khusus satuan ini')
                            ->schema([
                                Forms\Components\Select::make('price_type')
                                    ->label('Jenis harga')
                                    ->options(ProductPrice::TYPES)
                                    ->default('eceran')
                                    ->required(),
                                Forms\Components\TextInput::make('min_qty')
                                    ->label('Mulai jumlah')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0.001)
                                    ->required(),
                                Forms\Components\TextInput::make('price')
                                    ->label('Harga per satuan')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->prefix('Rp')
                                    ->required(),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Tambah harga')
                            ->helperText('Kosongkan untuk memakai harga dasar × isi. Isi untuk harga karung/grosir yang berbeda.')
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->default([[
                        'unit_id' => null,
                        'is_base' => true,
                        'conversion_qty' => 1,
                        'allow_decimal_qty' => false,
                        'is_sellable' => true,
                        'is_purchasable' => true,
                    ]])
                    ->minItems(1)
                    ->addActionLabel('Tambah satuan')
                    ->itemLabel(fn (array $state): ?string => static::unitItemLabel($state))
                    ->deleteAction(fn (Forms\Components\Actions\Action $action) => $action
                        ->requiresConfirmation()
                        ->hidden(fn (array $arguments, Repeater $component, $livewire): bool => static::isStockLocked($livewire)
                            && (bool) ($component->getRawItemState($arguments['item'])['is_base'] ?? false)))
                    ->rules([fn (): Closure => static::validateUnits(...)]),
            ])
            ->columnSpanFull();
    }

    /** Satuan dasar tidak boleh diubah setelah produk punya mutasi stok. */
    protected static function isStockLocked($livewire): bool
    {
        return $livewire instanceof EditRecord && $livewire->getRecord()->hasStockHistory();
    }

    protected static function unitItemLabel(array $state): ?string
    {
        $name = filled($state['unit_id'] ?? null) ? Unit::query()->whereKey($state['unit_id'])->value('name') : null;

        if (! $name) {
            return 'Satuan baru';
        }

        return ($state['is_base'] ?? false)
            ? "{$name} (satuan dasar)"
            : "{$name} = " . Product::formatQty($state['conversion_qty'] ?? 0) . ' satuan dasar';
    }

    protected static function validateUnits(string $attribute, mixed $value, Closure $fail): void
    {
        $items = collect($value ?? []);

        $baseCount = $items->filter(fn (array $item): bool => (bool) ($item['is_base'] ?? false))->count();
        if ($baseCount !== 1) {
            $fail('Produk harus punya tepat satu satuan dasar.');
        }

        $unitIds = $items->pluck('unit_id')->filter();
        if ($unitIds->count() !== $unitIds->unique()->count()) {
            $fail('Setiap satuan hanya boleh dipakai sekali per produk.');
        }

        $barcodes = $items->pluck('barcode')->filter(fn ($barcode): bool => filled($barcode));
        if ($barcodes->count() !== $barcodes->unique()->count()) {
            $fail('Barcode tidak boleh sama antar satuan.');
        }
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
