<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnitResource\Pages;
use App\Models\Unit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $modelLabel = 'Satuan';
    protected static ?string $pluralModelLabel = 'Satuan';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Contoh: Kg, Karung, Bungkus.'),
                Forms\Components\TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(20)
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : $state)
                    ->helperText('Kode singkat, mis. KG, KRG, BKS.'),
                Forms\Components\Toggle::make('allow_decimal_qty')
                    ->label('Boleh jumlah pecahan')
                    ->helperText('Aktifkan untuk satuan timbang/takar seperti Kg atau Liter (mis. jual 0,5 kg). Menjadi nilai awal saat satuan ini ditambahkan ke produk.'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->helperText('Satuan nonaktif tidak bisa dipilih untuk produk baru.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable(),
                Tables\Columns\IconColumn::make('allow_decimal_qty')
                    ->label('Pecahan')
                    ->boolean(),
                Tables\Columns\TextColumn::make('product_units_count')
                    ->label('Dipakai produk')
                    ->counts('productUnits')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Satuan yang sudah dipakai produk tidak boleh dihapus, cukup dinonaktifkan.
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (Unit $record): bool => $record->productUnits()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUnits::route('/'),
        ];
    }
}
