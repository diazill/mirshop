<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Enums\UserRole;
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
                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->required()
                            ->minLength(3)
                            ->maxLength(30)
                            ->regex('/^[A-Za-z0-9._-]+$/')
                            // Username disimpan huruf kecil, jadi cek unik tanpa membedakan huruf besar/kecil.
                            ->rule(fn (?User $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                $taken = User::query()
                                    ->whereRaw('lower(username) = ?', [mb_strtolower(trim((string) $value))])
                                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($taken) {
                                    $fail('Username sudah dipakai.');
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtolower(trim($state)) : null)
                            ->validationMessages(['regex' => 'Hanya huruf, angka, titik, garis bawah, dan tanda minus.'])
                            ->helperText('Dipakai untuk login di admin dan aplikasi HP. Huruf besar/kecil dianggap sama.'),
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
                            ->options(UserRole::options())
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
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->placeholder('Belum diisi')
                    ->copyable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Peran')
                    ->formatStateUsing(fn (string $state): string => UserRole::tryFrom($state)?->label() ?? $state),
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
                        // Hapus massal tidak memicu event model, jadi dicatat langsung.
                        \App\Support\AuditLogger::record(
                            action: 'token_revoked',
                            subjectType: 'user',
                            subjectId: $record->id,
                            subjectLabel: $record->username ?: $record->name,
                            context: ['jumlah_login' => $count],
                        );
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
