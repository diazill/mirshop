<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiTokenResource\Pages;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Login aplikasi HP yang aktif. Owner bisa mencabut satu per satu (mis. HP hilang). */
class ApiTokenResource extends Resource
{
    protected static ?string $model = PersonalAccessToken::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'Pengaturan';
    protected static ?string $modelLabel = 'Token API';
    protected static ?string $pluralModelLabel = 'Token API';
    protected static ?string $slug = 'token-api';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasRole('owner');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tokenable', 'device'])->where('tokenable_type', (new User())->getMorphClass()))
            ->defaultSort('last_used_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('tokenable.name')->label('User'),
                Tables\Columns\TextColumn::make('tokenable.role')->label('Peran')->badge(),
                Tables\Columns\TextColumn::make('device.code')->label('Perangkat')->placeholder('Tanpa perangkat'),
                Tables\Columns\TextColumn::make('name')->label('Nama HP'),
                Tables\Columns\TextColumn::make('last_used_at')->label('Terakhir dipakai')->since()->placeholder('Belum dipakai')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Berlaku sampai')
                    ->dateTime('d M Y')
                    ->placeholder('Tanpa batas')
                    ->color(fn (PersonalAccessToken $record): ?string => $record->expires_at?->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('created_at')->label('Login')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('device_id')->label('Perangkat')->relationship('device', 'code'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Cabut')
                    ->modalHeading('Cabut login ini?')
                    ->modalDescription('User harus login ulang di HP tersebut. Nota yang belum disinkron tetap tersimpan di HP.'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label('Cabut terpilih'),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->hasRole('owner');
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->hasRole('owner');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiTokens::route('/'),
        ];
    }
}
