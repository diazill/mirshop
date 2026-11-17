<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\Section;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Pengaturan';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                //card
                Section::make()
                    ->schema([

                        //name
                        Forms\Components\TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->label('Kata sandi')
                            ->password()
                            ->revealable()
                            ->rule(Password::defaults())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state)),
                        Forms\Components\Select::make('role')
                            ->label('Peran')
                            ->options([
                                'owner' => 'Owner',
                                'admin' => 'Admin',
                                'cashier' => 'Kasir',
                                'warehouse' => 'Staf gudang',
                            ])
                            ->default('admin')
                            ->visible(fn (): bool => auth()->user()?->hasRole('owner') ?? false)
                            ->disabled(fn (?User $record): bool => $record?->getKey() === auth()->id()),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Akun aktif')
                            ->default(true)
                            ->visible(fn (): bool => filled(auth()->user()))
                            ->disabled(fn (?User $record): bool => $record?->getKey() === auth()->id()),

                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Peran')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'owner' => 'Owner',
                        'admin' => 'Admin',
                        'cashier' => 'Kasir',
                        'warehouse' => 'Staf gudang',
                        default => $state,
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Untuk HP hilang: semua login aplikasi HP user ini berhenti berlaku.
                Tables\Actions\Action::make('revokeTokens')
                    ->label('Cabut login aplikasi')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('User harus login ulang di aplikasi HP. Data yang belum disinkronkan di HP tetap ada di HP.')
                    ->visible(fn (User $record): bool => (bool) auth()->user()?->hasRole('owner'))
                    ->action(function (User $record): void {
                        $count = $record->tokens()->delete();
                        \Filament\Notifications\Notification::make()->success()->title("{$count} login dicabut.")->send();
                    }),
            ])
            ->bulkActions([]);
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasRole('owner') || ! $record->hasRole('owner');
    }
}
