<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** HP kasir/gudang yang boleh sinkron. Nonaktifkan bila HP hilang. */
class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';
    protected static ?string $navigationGroup = 'Pengaturan';
    protected static ?string $modelLabel = 'Perangkat';
    protected static ?string $pluralModelLabel = 'Perangkat';
    protected static ?string $slug = 'perangkat';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Kode')
                ->required()
                ->unique(ignoreRecord: true)
                ->regex('/^[A-Za-z0-9]{2,10}$/')
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : $state)
                ->disabledOn('edit')
                ->helperText('Mis. K01. Jadi awalan nomor nota offline (K01-20261114-0001), tidak bisa diubah.'),
            Forms\Components\TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255)
                ->helperText('Mis. HP kasir depan.'),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Matikan bila HP hilang: semua login dari HP ini langsung ditolak, termasuk sinkron.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
                Tables\Columns\TextColumn::make('last_synced_at')->label('Sinkron terakhir')->since()->placeholder('Belum pernah'),
                Tables\Columns\TextColumn::make('lastSyncedBy.name')->label('Oleh'),
                Tables\Columns\TextColumn::make('users.name')->label('Pernah login')->badge()->limitList(3),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDevices::route('/'),
        ];
    }
}
