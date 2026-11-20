<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\TransactionsRelationManager;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Pelanggan toko. Bisa juga dibuat kasir dari aplikasi HP (tambah cepat). */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Penjualan';
    protected static ?string $modelLabel = 'Pelanggan';
    protected static ?string $pluralModelLabel = 'Pelanggan';
    protected static ?string $slug = 'pelanggan';
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?int $navigationSort = 2;

    /** Hapus hanya owner; admin cukup menonaktifkan. Nota lama tetap menyimpan nama pelanggan. */
    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->hasRole('owner');
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return (bool) auth()->user()?->hasRole('owner');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(100),
            Forms\Components\TextInput::make('phone')
                ->label('Nomor HP')
                ->tel()
                ->maxLength(25)
                ->helperText('Boleh kosong. Satu nomor hanya untuk satu pelanggan; disimpan sebagai 08xxx.')
                ->rules([
                    fn (?Customer $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        if (blank($value)) {
                            return;
                        }

                        if (! Customer::isValidPhone((string) $value)) {
                            $fail('Nomor HP tidak valid. Contoh: 0812 3456 7890.');

                            return;
                        }

                        if ($owner = Customer::findByPhone((string) $value, $record?->id)) {
                            $fail("Nomor ini sudah dipakai pelanggan {$owner->name}.");
                        }
                    },
                ]),
            Forms\Components\Textarea::make('address')->label('Alamat')->maxLength(500)->rows(2)->columnSpanFull(),
            Forms\Components\Textarea::make('notes')->label('Catatan')->maxLength(1000)->rows(2)->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Pelanggan nonaktif tidak muncul di kasir dan tidak bisa dipilih untuk nota baru.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->label('Nomor HP')->searchable()->placeholder('-'),
                Tables\Columns\TextColumn::make('address')->label('Alamat')->limit(40)->placeholder('-')->toggleable(),
                Tables\Columns\TextColumn::make('transactions_count')->label('Nota')->counts('transactions')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
                Tables\Columns\TextColumn::make('creator.name')->label('Dibuat oleh')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
                Tables\Filters\TrashedFilter::make()->label('Terhapus'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (Customer $record): string => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (Customer $record): string => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (Customer $record): string => $record->is_active ? 'warning' : 'success')
                    ->visible(fn (Customer $record): bool => ! $record->trashed())
                    ->requiresConfirmation()
                    ->action(function (Customer $record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                        Notification::make()->success()->title($record->is_active ? 'Pelanggan diaktifkan.' : 'Pelanggan dinonaktifkan.')->send();
                    }),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make()
                    ->before(function (Customer $record, Tables\Actions\RestoreAction $action): void {
                        if ($owner = Customer::findByPhone($record->phone, $record->id)) {
                            Notification::make()->danger()
                                ->title('Tidak bisa dipulihkan')
                                ->body("Nomor {$record->phone} sekarang dipakai pelanggan {$owner->name}.")
                                ->send();
                            $action->halt();
                        }
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('creator')
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [TransactionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
